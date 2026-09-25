<?php

namespace Cloudexus\Model\Crm;

use Cloudexus\Core\DatabaseConnection;

class PartnerActivityModel
{
    public function forPartner(int $partnerId): array
    {
        $stmt = DatabaseConnection::get()->prepare(
            'SELECT a.*, u.full_name AS created_by_name, c.name AS contact_name
             FROM partner_activities a
             LEFT JOIN users u ON u.id = a.created_by
             LEFT JOIN partner_contacts c ON c.id = a.contact_id
             WHERE a.partner_id = :id
             ORDER BY a.activity_date DESC, a.id DESC'
        );
        $stmt->execute(['id' => $partnerId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = DatabaseConnection::get()->prepare('SELECT * FROM partner_activities WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = DatabaseConnection::get()->prepare(
            'INSERT INTO partner_activities (partner_id, contact_id, type, subject, note, activity_date, created_by, created_at)
             VALUES (:partner_id, :contact_id, :type, :subject, :note, :activity_date, :created_by, NOW())'
        );
        $stmt->execute([
            'partner_id' => $data['partner_id'],
            'contact_id' => !empty($data['contact_id']) ? (int) $data['contact_id'] : null,
            'type' => $data['type'],
            'subject' => $data['subject'],
            'note' => $data['note'] ?: null,
            'activity_date' => $data['activity_date'],
            'created_by' => $data['created_by'] ?: null,
        ]);
        return (int) DatabaseConnection::get()->lastInsertId();
    }

    public function delete(int $id): void
    {
        DatabaseConnection::get()->prepare('DELETE FROM partner_activities WHERE id = :id')->execute(['id' => $id]);
    }
}
