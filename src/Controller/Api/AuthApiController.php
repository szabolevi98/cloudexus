<?php

namespace Cloudexus\Controller\Api;

use Cloudexus\Core\Config;
use Cloudexus\Core\Permissions;
use Cloudexus\Core\RoleCode;
use Cloudexus\Model\Account\RoleModel;
use Cloudexus\Model\Account\UserModel;
use Cloudexus\Model\Account\UserTokenModel;

/**
 * Per-user sign-in for the mobile / PDA app. The token it hands out works on
 * every /api/* endpoint, and stock movements booked with it are credited to
 * the user (created_by), which an integration token cannot do.
 */
class AuthApiController extends ApiController
{
    private const FAILED_LOGIN_WINDOW_SECONDS = 900;

    /**
     * Hash of a throwaway password, verified against on an unknown username so
     * the response time gives nothing away. Same cost (12) as PHP 8.4's default.
     */
    private const DUMMY_HASH = '$2y$12$jO89ZAVP/kVCyTaGJmAJ4e2blfEETOPAg9slR3lR3HUnfN2tk8Fp2';

    public function login(): void
    {
        $log = $this->startLog();

        $maxFailures = (int) Config::get('api.login_max_failures', 10);
        if ($log->countFailedLogins($this->clientIp(), $this->requestPath(), self::FAILED_LOGIN_WINDOW_SECONDS) >= $maxFailures) {
            $this->error('Too many failed sign-in attempts. Try again in 15 minutes.', 429);
        }

        $body = $this->body();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($username === '' || $password === '') {
            $this->error('username and password are required.', 422);
        }

        $users = new UserModel();
        $user = $users->findByUsernameOrEmail($username);
        $passwordOk = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);
        if (!$user || !$passwordOk || !$user['is_active']) {
            $this->error('Invalid username or password.', 401);
        }

        $deviceName = trim((string) ($body['device_name'] ?? ''));
        $issued = (new UserTokenModel())->issue((int) $user['id'], $deviceName, $this->userTokenLifetimeDays());
        $users->touchLastLogin((int) $user['id']);

        $this->json(['data' => [
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'user' => $this->userPayload($user),
        ]], 201);
    }

    /** Signs out this device only; the user's other devices keep their tokens. */
    public function logout(): void
    {
        $this->requireUser();

        (new UserTokenModel())->revoke((int) $this->user['token_id']);

        $this->json(['data' => ['signed_out' => true]]);
    }

    /** The user behind the token, for the app to check its stored token at start-up. */
    public function me(): void
    {
        $this->requireUser();

        $this->json(['data' => [
            'user' => $this->userPayload($this->user),
            'expires_at' => date('Y-m-d H:i:s', time() + $this->userTokenLifetimeDays() * 86400),
        ]]);
    }

    private function userPayload(array $user): array
    {
        $roles = new RoleModel();
        $role = $roles->forUser((int) $user['id']);

        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'role_code' => $role['code'] ?? null,
            'role_name' => $role['name'] ?? null,
            // The app can hide what the role cannot do; the server still checks every call.
            'permissions' => $role === null ? [] : ($role['code'] === RoleCode::SUPER_ADMIN
                ? Permissions::all()
                : $roles->permissions((int) $role['id'])),
        ];
    }
}
