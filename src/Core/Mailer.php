<?php

namespace Cloudexus\Core;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Levélküldés: számlák a vevőknek, az elfelejtett jelszó linkje, a reggeli
 * összefoglaló.
 *
 * Hogy merre megy, azt a config.ini [mail] szakasza dönti el:
 *
 *   transport = mail   ; a PHP saját mail()-je, a gép sendmailjén át
 *   transport = smtp   ; egy levelezőszerver: host, port, username, password, encryption
 *   transport = file   ; .eml fájlok a var/mail mappába — fejlesztéshez, ahol
 *                      ; semmi nem juthat valódi postafiókba
 *   transport = none   ; semmilyen levél
 *
 * Levél nélkül az alkalmazás ott mondja, ahol számít: az elfelejtett jelszó
 * helyett az adminhoz kell fordulni, a számla küldése gomb nem jelenik meg.
 * A régi, transport nélküli beállítás host-tal smtp-nek számít, anélkül none.
 */
class Mailer
{
    public const TRANSPORTS = ['mail', 'smtp', 'file', 'none'];

    /**
     * Címvégződések, ahová levél nem jut el — példákra, tesztekre és saját
     * gépekre fenntartott nevek. Egy ide küldött levél csak visszapattanna.
     */
    private const NOWHERE = ['test', 'example', 'invalid', 'localhost', 'local', 'demo'];

    /** @var list<array{to: string, subject: string, body: string, attachment: ?string}> ami ebben a kérésben ment, a teszteknek */
    public static array $sent = [];

    /** Oda tud-e menni levél erre a címre. */
    public static function reachable(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $domain = strtolower((string) substr((string) strrchr($address, '@'), 1));
        $ending = (string) substr((string) strrchr('.' . $domain, '.'), 1);

        return !in_array($ending, self::NOWHERE, true);
    }

    public static function isConfigured(): bool
    {
        return self::transport() !== 'none';
    }

    /** @param array<string, mixed>|null $config a [mail] szakasz, vagy null az alkalmazásé */
    public static function transport(?array $config = null): string
    {
        $config ??= self::config();
        $chosen = strtolower(trim((string) ($config['transport'] ?? '')));

        if (in_array($chosen, self::TRANSPORTS, true)) {
            return $chosen;
        }

        return trim((string) ($config['host'] ?? '')) !== '' ? 'smtp' : 'none';
    }

    /**
     * Egy levél a sorba, hogy percen belül kimenjen, és ha a szerver nem
     * fogadja, később újra próbálkozzon. Igaz, ha úton van: nem, ha nincs
     * levélküldés, és nem egy olyan címre, ahová semmi nem jut el.
     */
    public static function send(string $toAddress, string $toName, string $subject, string $text, string $kind = 'mail', ?string $attachmentName = null, ?string $attachment = null): bool
    {
        if (!self::handOver($toAddress, $subject, $text, $attachmentName)) {
            return false;
        }

        try {
            (new Outbox())->add($toAddress, $toName, $subject, $text, $kind, $attachmentName, $attachment);
        } catch (\Throwable $e) {
            Logger::error('Mail could not be put in the outbox: ' . $e->getMessage(), ['to' => $toAddress, 'subject' => $subject]);

            return false;
        }

        return true;
    }

    /**
     * Egy levél azonnal — az elfelejtett jelszó linkje, amire valaki ott
     * vár —, és ha most nem megy ki, a sorban marad újrapróbálásra.
     */
    public static function sendNow(string $toAddress, string $toName, string $subject, string $text, string $kind = 'mail'): bool
    {
        if (!self::handOver($toAddress, $subject, $text, null)) {
            return false;
        }

        try {
            $outbox = new Outbox();

            return $outbox->sendOne($outbox->add($toAddress, $toName, $subject, $text, $kind));
        } catch (\Throwable $e) {
            Logger::error('Mail could not be put in the outbox: ' . $e->getMessage(), ['to' => $toAddress, 'subject' => $subject]);

            return false;
        }
    }

    /**
     * Mehet-e egyáltalán a levél: van levélküldés, és a cím elérhető
     * (fájlba írva bármi mehet). A tesztek így is, úgy is látják.
     */
    private static function handOver(string $toAddress, string $subject, string $text, ?string $attachmentName): bool
    {
        if (!self::isConfigured()) {
            return false;
        }

        self::$sent[] = ['to' => $toAddress, 'subject' => $subject, 'body' => $text, 'attachment' => $attachmentName];

        return self::transport() === 'file' ? filter_var($toAddress, FILTER_VALIDATE_EMAIL) !== false : self::reachable($toAddress);
    }

    /**
     * Egy levél elküldése a beállítás szerint. Null, ha elment, különben hogy
     * mi volt a baj — naplózva, sosem dobva: egy leállt levelezőszerver nem
     * vihet magával mást.
     */
    public static function deliver(string $toAddress, string $toName, string $subject, string $text, ?string $attachmentName = null, ?string $attachment = null): ?string
    {
        try {
            $config = self::config();
            $transport = self::transport($config);
            if ($transport === 'none') {
                throw new \RuntimeException('Email is turned off.');
            }

            $mail = new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $mail->setFrom(self::fromAddress($config), (string) ($config['from_name'] ?? 'Cloudexus'));
            $mail->addAddress($toAddress, $toName);
            $mail->Subject = $subject;
            // CRLF sorvégek: a quoted-printable különben a puszta \n-t is kódolja (=0A), és a levél egy sorba folyik.
            $mail->Body = (string) preg_replace('/\r\n|\r|\n/', PHPMailer::CRLF, $text);
            $mail->isHTML(false);
            if ($attachmentName !== null && $attachment !== null) {
                $mail->addStringAttachment($attachment, $attachmentName);
            }

            if ($transport === 'file') {
                $mail->preSend();

                return self::toFile($mail->getSentMIMEMessage()) ? null : 'The message could not be written to var/mail.';
            }

            if ($transport === 'smtp') {
                $encryption = strtolower(trim((string) ($config['encryption'] ?? 'tls')));
                $mail->isSMTP();
                $mail->Host = trim((string) ($config['host'] ?? ''));
                $mail->Port = (int) ($config['port'] ?? 0) ?: ($encryption === 'ssl' ? 465 : 587);
                $mail->SMTPAuth = trim((string) ($config['username'] ?? '')) !== '';
                $mail->Username = (string) ($config['username'] ?? '');
                $mail->Password = (string) ($config['password'] ?? '');
                $mail->SMTPSecure = match ($encryption) {
                    'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                    'none' => '',
                    default => PHPMailer::ENCRYPTION_STARTTLS,
                };
                $mail->SMTPAutoTLS = $encryption !== 'none';
                $mail->Timeout = 20;
            } else {
                // mail(): a feladó a boríték feladója is, így az a cím látszik,
                // amelyért a domainje kezeskedik (SPF).
                $mail->isMail();
                $mail->Sender = self::fromAddress($config);
            }

            $mail->send();

            return null;
        } catch (\Throwable $e) {
            Logger::error('Mail could not be sent: ' . $e->getMessage(), ['to' => $toAddress, 'subject' => $subject]);

            return mb_substr($e->getMessage(), 0, 500);
        }
    }

    /** @param array<string, mixed> $config */
    private static function fromAddress(array $config): string
    {
        return (string) ($config['from_address'] ?? $config['from'] ?? 'no-reply@cloudexus.local');
    }

    private static function toFile(string $message): bool
    {
        $folder = dirname(__DIR__, 2) . '/var/mail';
        if (!is_dir($folder)) {
            @mkdir($folder, 0775, true);
        }

        return file_put_contents($folder . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml', $message) !== false;
    }

    /** @return array<string, mixed> a [mail] szakasz */
    private static function config(): array
    {
        $section = Config::get('mail', []);

        return is_array($section) ? $section : [];
    }
}
