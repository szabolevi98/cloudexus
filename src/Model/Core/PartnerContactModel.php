<?php

namespace Cloudexus\Model\Core;

use Cloudexus\Core\DatabaseConnection;

/** A partnerek kapcsolattartói — lásd a 18_partner_contacts.sql migrációt. */
class PartnerContactModel
{
    /** @return list<array<string, mixed>> az elsődleges elöl, aztán név szerint */
    public function forPartner(int $partnerId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT * FROM partner_contacts WHERE partner_id = :id ORDER BY is_primary DESC, name'
        );
        $stmt->execute(['id' => $partnerId]);

        return array_values($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM partner_contacts WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** @param array{name: string, position: string, email: string, phone: string, note: string, is_primary: bool, receives_invoices: bool} $data */
    public function save(int $partnerId, ?int $id, array $data): int
    {
        $pdo = DatabaseConnection::get();
        $pdo->beginTransaction();
        try {
            // Egy partnernek egy elsődleges és egy számlát fogadó kapcsolattartója van.
            foreach (['is_primary', 'receives_invoices'] as $flag) {
                if ($data[$flag]) {
                    $pdo->prepare("UPDATE partner_contacts SET $flag = 0 WHERE partner_id = :partner")->execute(['partner' => $partnerId]);
                }
            }
            $params = [
                'name' => $data['name'],
                'position' => $data['position'] !== '' ? $data['position'] : null,
                'email' => $data['email'] !== '' ? $data['email'] : null,
                'phone' => $data['phone'] !== '' ? $data['phone'] : null,
                'note' => $data['note'] !== '' ? $data['note'] : null,
                'is_primary' => $data['is_primary'] ? 1 : 0,
                'receives_invoices' => $data['receives_invoices'] ? 1 : 0,
            ];
            if ($id === null) {
                $pdo->prepare(
                    'INSERT INTO partner_contacts (partner_id, name, position, email, phone, note, is_primary, receives_invoices)
                     VALUES (:partner, :name, :position, :email, :phone, :note, :is_primary, :receives_invoices)'
                )->execute($params + ['partner' => $partnerId]);
                $id = (int) $pdo->lastInsertId();
            } else {
                $pdo->prepare(
                    'UPDATE partner_contacts SET name = :name, position = :position, email = :email, phone = :phone, note = :note,
                            is_primary = :is_primary, receives_invoices = :receives_invoices WHERE id = :id AND partner_id = :partner'
                )->execute($params + ['id' => $id, 'partner' => $partnerId]);
            }
            $pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $partnerId, int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM partner_contacts WHERE id = :id AND partner_id = :partner')
            ->execute(['id' => $id, 'partner' => $partnerId]);
        DatabaseConnection::get()->prepare('UPDATE partner_activities SET contact_id = NULL WHERE contact_id = :id')->execute(['id' => $id]);
    }

    /**
     * Kinek menjen egy bizonylat e-mailben: számlánál a számlát fogadó, aztán
     * az elsődleges kapcsolattartó, végül a partner saját címe.
     */
    public function recipientFor(int $partnerId, bool $invoice, ?string $fallback): ?string
    {
        $order = $invoice ? 'receives_invoices DESC, is_primary DESC' : 'is_primary DESC, receives_invoices DESC';
        $stmt = DatabaseConnection::get()->prepare(
            "SELECT email FROM partner_contacts WHERE partner_id = :id AND email IS NOT NULL AND (is_primary = 1 OR receives_invoices = 1)
             ORDER BY $order LIMIT 1"
        );
        $stmt->execute(['id' => $partnerId]);
        $email = $stmt->fetchColumn();

        return is_string($email) && $email !== '' ? $email : $fallback;
    }
}
