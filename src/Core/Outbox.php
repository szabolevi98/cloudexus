<?php

namespace Cloudexus\Core;

use PDO;

/**
 * A kimenő levelek sora — lásd a 10_outbox.sql migrációt.
 *
 * A levelet a Mailer::send teszi ide, és a bin/outbox.php küldi ki, cronból,
 * percen belül. Amit a szerver nem fogad, azt egyre később újra próbálja —
 * egy, öt, tizenöt perc, egy, négy óra —, utána hibásként marad, az okkal, a
 * Beállítások → E-mail oldalon, ahonnan kézzel újraküldhető.
 */
final class Outbox
{
    /** Várakozás percben minden sikertelen próba után; eggyel több próba, és hibás. */
    public const BACKOFF = [1, 5, 15, 60, 240];

    /** Egy futás, ami elvitt egy levelet, és nem mondta meg, mi lett vele, meghalt; a levele visszakerül. */
    private const STALE_MINUTES = 10;

    private PDO $db;

    /** @var callable(string, string, string, string, ?string, ?string): ?string */
    private $deliver;

    /**
     * @param (callable(string, string, string, string, ?string, ?string): ?string)|null $deliver hogyan megy
     *        ki egy levél — a Mailer::deliver, hacsak egy teszt mást nem mond; null, ha elment, különben miért nem
     */
    public function __construct(?PDO $db = null, ?callable $deliver = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
        $this->deliver = $deliver ?? Mailer::deliver(...);
    }

    public function add(string $toAddress, string $toName, string $subject, string $body, string $kind = 'mail', ?string $attachmentName = null, ?string $attachment = null): int
    {
        $this->db->prepare(
            'INSERT INTO outbox (to_address, to_name, subject, body, kind, attachment_name, attachment)
             VALUES (:address, :name, :subject, :body, :kind, :attachment_name, :attachment)'
        )->execute([
            'address' => mb_substr($toAddress, 0, 255),
            'name' => mb_substr($toName, 0, 160),
            'subject' => mb_substr($subject, 0, 255),
            'body' => $body,
            'kind' => mb_substr($kind, 0, 20),
            'attachment_name' => $attachmentName === null ? null : mb_substr($attachmentName, 0, 190),
            'attachment' => $attachment,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Kiküldi, ami esedékes, a legrégebbit előbb, legfeljebb $limit darabot.
     * Minden levelet előbb elvisz, és csak utána küld, így két egyszerre futó
     * küldő sosem küldi ugyanazt kétszer. Visszaadja, hány ment el.
     */
    public function sendDue(int $limit = 100): int
    {
        $this->db->exec(
            "UPDATE outbox SET state = 'waiting' WHERE state = 'sending' AND taken_at < NOW() - INTERVAL " . self::STALE_MINUTES . ' MINUTE'
        );

        $due = $this->db->query(
            "SELECT id FROM outbox WHERE state = 'waiting' AND next_attempt_at <= NOW() ORDER BY next_attempt_at, id LIMIT " . max(1, $limit)
        );
        $ids = $due === false ? [] : array_map('intval', $due->fetchAll(PDO::FETCH_COLUMN));
        $sent = 0;

        foreach ($ids as $id) {
            $sent += $this->sendOne($id) ? 1 : 0;
        }

        return $sent;
    }

    /** Egy várakozó levél azonnal. Igaz, ha elment. */
    public function sendOne(int $id): bool
    {
        $take = $this->db->prepare("UPDATE outbox SET state = 'sending', taken_at = NOW() WHERE id = :id AND state = 'waiting'");
        $take->execute(['id' => $id]);

        if ($take->rowCount() === 0) {
            return false;
        }

        $read = $this->db->prepare('SELECT * FROM outbox WHERE id = :id');
        $read->execute(['id' => $id]);
        $message = (array) $read->fetch();
        $error = ($this->deliver)(
            (string) $message['to_address'],
            (string) $message['to_name'],
            (string) $message['subject'],
            (string) $message['body'],
            $message['attachment_name'] === null ? null : (string) $message['attachment_name'],
            $message['attachment'] === null ? null : (string) $message['attachment'],
        );

        if ($error !== null) {
            $this->failed($id, (int) $message['attempts'], $error);

            return false;
        }

        $this->db->prepare("UPDATE outbox SET state = 'sent', sent_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = :id")
            ->execute(['id' => $id]);

        return true;
    }

    /** Egy sikertelen próba: mikor jön a következő, vagy hogy nem jön több. */
    public function failed(int $id, int $attemptsBefore, string $error): void
    {
        $attempts = $attemptsBefore + 1;
        $wait = self::BACKOFF[$attempts - 1] ?? null;

        $this->db->prepare(
            'UPDATE outbox SET state = :state, attempts = :attempts, last_error = :error,
                    next_attempt_at = NOW() + INTERVAL :wait MINUTE, taken_at = NULL WHERE id = :id'
        )->execute([
            'state' => $wait === null ? 'failed' : 'waiting',
            'attempts' => $attempts,
            'error' => mb_substr($error, 0, 500),
            'wait' => $wait ?? 0,
            'id' => $id,
        ]);
    }

    /** Egy hibás levél kézzel újra: most esedékes, a próbái elölről számolva. */
    public function retry(int $id): bool
    {
        $statement = $this->db->prepare(
            "UPDATE outbox SET state = 'waiting', attempts = 0, next_attempt_at = NOW() WHERE id = :id AND state IN ('failed', 'waiting')"
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    /** @return array{waiting: int, failed: int, sent_today: int} */
    public function counts(): array
    {
        $row = $this->db->query(
            "SELECT COALESCE(SUM(state IN ('waiting', 'sending')), 0) AS waiting, COALESCE(SUM(state = 'failed'), 0) AS failed,
                    COALESCE(SUM(state = 'sent' AND sent_at >= CURDATE()), 0) AS sent_today FROM outbox"
        );
        $counts = $row === false ? [] : (array) $row->fetch();

        return ['waiting' => (int) ($counts['waiting'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0), 'sent_today' => (int) ($counts['sent_today'] ?? 0)];
    }

    /**
     * Egy állapot levelei, a legújabb elöl — a melléklet nélkül.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(string $state, int $limit = 50): array
    {
        $states = $state === 'waiting' ? "'waiting', 'sending'" : $this->db->quote($state);
        $order = $state === 'sent' ? 'sent_at DESC' : 'created_at DESC';
        $statement = $this->db->query(
            'SELECT id, to_address, to_name, subject, kind, state, attempts, next_attempt_at, last_error, created_at, sent_at, attachment_name
             FROM outbox WHERE state IN (' . $states . ') ORDER BY ' . $order . ', id DESC LIMIT ' . max(1, $limit)
        );

        return $statement === false ? [] : array_values($statement->fetchAll());
    }

    /** A hónapnál régebbi elküldött levelek törlése. Visszaadja, hányat. */
    public function prune(): int
    {
        return (int) $this->db->exec("DELETE FROM outbox WHERE state = 'sent' AND sent_at < NOW() - INTERVAL 30 DAY");
    }
}
