<?php

namespace Cloudexus\Core;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PDO;

/**
 * A belépés második lépése: egy kód a hitelesítő alkalmazásból, vagy a tíz
 * helyreállító kód egyike arra a napra, amikor a telefon nincs meg.
 *
 * A felhasználó maga kapcsolja be a profiljában, és csak az első jó kód
 * beírása után lesz bekapcsolva — egy rosszul beolvasott kulcs különben a
 * következő belépésnél kizárná. A webes belépés és az API (a raktári
 * alkalmazás) egyaránt kéri.
 */
class TwoFactor
{
    public const RECOVERY_CODES = 10;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** @param array<string, mixed> $user a users tábla sora */
    public static function isOn(array $user): bool
    {
        return !empty($user['totp_secret']) && !empty($user['totp_enabled_at']);
    }

    /** Egy új kulcs QR-kódja SVG-ként, az alkalmazásba olvasáshoz. */
    public static function qr(string $account, string $secret): string
    {
        $uri = Totp::uri('Cloudexus', $account, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd()));

        // XML-fejléc nélkül, hogy az oldalba ágyazható legyen.
        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $writer->writeString($uri));
    }

    /**
     * Bekapcsolja egy kulccsal, amiről a felhasználó bebizonyította, hogy
     * nála van, és visszaadja a helyreállító kódjait — ezek csak egyszer
     * látszanak.
     *
     * @return list<string>|null null, ha a kód nem jó
     */
    public function enable(int $userId, string $secret, string $code): ?array
    {
        $step = Totp::verify($secret, $code);

        if ($step === null) {
            return null;
        }

        $this->db->prepare(
            'UPDATE users SET totp_secret = :secret, totp_enabled_at = NOW(), totp_last_step = :step WHERE id = :id'
        )->execute(['secret' => $secret, 'step' => $step, 'id' => $userId]);

        return $this->newRecoveryCodes($userId);
    }

    public function disable(int $userId): void
    {
        $this->db->prepare('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = :id')
            ->execute(['id' => $userId]);
        $this->db->prepare('DELETE FROM user_recovery_codes WHERE user_id = :id')->execute(['id' => $userId]);
    }

    /**
     * Jó-e a kód az alkalmazásból, vagy egy helyreállító kód — és el is
     * használja, így egyik sem jó kétszer.
     *
     * @param array<string, mixed> $user a users tábla sora
     */
    public function check(array $user, string $given): bool
    {
        $given = trim($given);

        if (preg_match('/^\d{3}\s?\d{3}$/', $given) === 1) {
            $step = Totp::verify((string) $user['totp_secret'], $given, isset($user['totp_last_step']) ? (int) $user['totp_last_step'] : null);

            if ($step === null) {
                return false;
            }

            // Csak előre léphet: két, ugyanazzal a kóddal versenyző belépésből
            // csak az egyik nyerhet.
            $statement = $this->db->prepare(
                'UPDATE users SET totp_last_step = :step WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :step2)'
            );
            $statement->execute(['step' => $step, 'step2' => $step, 'id' => $user['id']]);

            return $statement->rowCount() === 1;
        }

        return $this->useRecoveryCode((int) $user['id'], $given);
    }

    /** @return list<string> */
    public function newRecoveryCodes(int $userId): array
    {
        $this->db->prepare('DELETE FROM user_recovery_codes WHERE user_id = :id')->execute(['id' => $userId]);
        $insert = $this->db->prepare('INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (:user, :hash)');
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            // Tíz karakter összetéveszthető betűk nélkül (nincs 0/o, 1/l).
            $code = '';
            for ($c = 0; $c < 10; $c++) {
                $code .= 'abcdefghjkmnpqrstuvwxyz23456789'[random_int(0, 30)];
            }

            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
            $insert->execute(['user' => $userId, 'hash' => password_hash($code, PASSWORD_DEFAULT)]);
        }

        return $codes;
    }

    public function recoveryCodesLeft(int $userId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :id AND used_at IS NULL');
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    private function useRecoveryCode(int $userId, string $given): bool
    {
        $given = strtolower(str_replace(['-', ' '], '', $given));

        if (strlen($given) !== 10) {
            return false;
        }

        $statement = $this->db->prepare('SELECT id, code_hash FROM user_recovery_codes WHERE user_id = :id AND used_at IS NULL');
        $statement->execute(['id' => $userId]);

        foreach ($statement->fetchAll() as $row) {
            if (password_verify($given, (string) $row['code_hash'])) {
                $used = $this->db->prepare('UPDATE user_recovery_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
                $used->execute(['id' => $row['id']]);

                return $used->rowCount() === 1;
            }
        }

        return false;
    }
}
