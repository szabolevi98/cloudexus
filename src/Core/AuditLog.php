<?php

namespace Cloudexus\Core;

/**
 * Ki, mikor, mit csinált. Egy sor egy esemény: a végző felhasználó (a neve
 * is, hogy egy törölt felhasználó sorai is olvashatók maradjanak), a
 * művelet, a tárgya és egy rövid leírás.
 *
 * Egy napló-írás sosem akaszthatja meg a kérést: ha nem sikerül, az csak a
 * hibanaplóba kerül.
 */
final class AuditLog
{
    public const LOGIN = 'login';
    public const LOGIN_FAILED = 'login_failed';
    public const LOGOUT = 'logout';
    public const DENIED = 'permission_denied';
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    public const PERMISSIONS = 'permissions';
    public const ISSUE = 'issue';
    public const STORNO = 'storno';
    public const PAID = 'paid';
    public const BOOK = 'book';
    public const TWO_FACTOR_ON = 'two_factor_on';
    public const TWO_FACTOR_OFF = 'two_factor_off';

    /** A napló szűrőjében választható műveletek. */
    public const ACTIONS = [
        self::LOGIN, self::LOGIN_FAILED, self::LOGOUT, self::DENIED, self::CREATE, self::UPDATE,
        self::DELETE, self::PERMISSIONS, self::ISSUE, self::STORNO, self::PAID, self::BOOK,
        self::TWO_FACTOR_ON, self::TWO_FACTOR_OFF,
    ];

    /**
     * @param string|null $label a tárgy olvasható neve (számlaszám, felhasználónév…)
     * @param array<string, mixed>|string|null $details részletek, pl. a hozzáadott és elvett jogok
     * @param array{id: int, name: string}|null $actor ha nem a bejelentkezett felhasználó (pl. sikertelen belépés)
     */
    public static function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $label = null,
        array|string|null $details = null,
        ?array $actor = null
    ): void {
        try {
            $userId = $actor['id'] ?? Auth::id();
            $userName = $actor['name'] ?? Auth::name();

            DatabaseConnection::get()->prepare(
                'INSERT INTO audit_log (user_id, user_name, action, entity_type, entity_id, label, details, ip)
                 VALUES (:user_id, :user_name, :action, :entity_type, :entity_id, :label, :details, :ip)'
            )->execute([
                'user_id' => $userId,
                'user_name' => $userName === null ? null : mb_substr($userName, 0, 120),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'label' => $label === null ? null : mb_substr($label, 0, 255),
                'details' => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
                'ip' => ClientIp::get(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Az audit napló írása sikertelen: ' . $e->getMessage());
        }
    }
}
