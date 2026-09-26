<?php

namespace Cloudexus\Controller\Api;

use Cloudexus\Core\ClientIp;
use Cloudexus\Core\Config;
use Cloudexus\Core\Currency;
use Cloudexus\Core\Language;
use Cloudexus\Core\Paginator;
use Cloudexus\Model\Account\ApiRequestLogModel;
use Cloudexus\Model\Account\ApiUserModel;
use Cloudexus\Model\Account\IdempotencyKeyModel;
use Cloudexus\Model\Account\UserTokenModel;

/**
 * Base for all /api/* endpoints: bearer-token auth, JSON in/out, and a
 * shared {data, meta} pagination envelope. Does NOT use sessions/CSRF/Twig.
 */
abstract class ApiController
{
    protected const DEFAULT_PER_PAGE = 50;
    protected const MAX_PER_PAGE = 200;

    /** The integration (api_users) behind the token, or null for a user token. */
    protected ?array $apiUser = null;

    /** The signed-in user behind a per-user token (mobile app), or null for an integration token. */
    protected ?array $user = null;

    /**
     * An endpoint that handles its Idempotency-Key itself, inside its own
     * transaction (the stock bookings), sets this; every other change gets
     * the handling in authenticate().
     */
    protected const OWN_IDEMPOTENCY = false;

    /** The Idempotency-Key claimed for this request, [owner, key], while its answer is due. */
    private ?array $idempotency = null;

    /**
     * Rejects the request with 401 unless a valid, active token is present,
     * and with 429 if the token's rate limit is exceeded. Every call also
     * logs the request to api_request_logs (see ApiRequestLogModel) — this
     * is the single hook point for every /api/* endpoint, so no individual
     * controller needs to touch logging or rate limiting itself.
     *
     * Two kinds of token are accepted: an integration token from the admin UI
     * (api_users), and a per-user token from POST /api/auth/login (user_tokens).
     */
    protected function authenticate(): void
    {
        $token = $this->bearerToken();
        $userTokens = new UserTokenModel();
        $this->user = $userTokens->findActiveUser($token);
        if (!$this->user) {
            $this->apiUser = (new ApiUserModel())->findActiveByToken($token);
        }

        $log = $this->startLog();

        // Traffic-driven self-cleanup: no cron dependency, negligible overhead.
        if (random_int(1, 100) === 1) {
            $log->purgeOlderThan((int) Config::get('api.log_retention_days', 14));
            $userTokens->purgeExpired();
            (new IdempotencyKeyModel())->purgeOlderThan(7);
        }

        if (!$this->apiUser && !$this->user) {
            $this->error('Invalid or missing API token.', 401);
        }

        if ($this->user) {
            $userTokens->touch((int) $this->user['token_id'], $this->userTokenLifetimeDays());
        }

        $limit = (int) Config::get('api.rate_limit_per_minute', 60);
        $recentCount = $this->user
            ? $log->countRecent('user_id', (int) $this->user['id'], 60)
            : $log->countRecent('api_user_id', (int) $this->apiUser['id'], 60);
        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . max(0, $limit - $recentCount));
        if ($recentCount > $limit) {
            $this->error('Rate limit exceeded. Try again later.', 429);
        }

        $this->applyLanguage();
        $this->idempotent();
    }

    /**
     * A change (POST, PUT, PATCH, DELETE) sent with an Idempotency-Key is done
     * once, however many times it arrives: the same key and the same request
     * again, from the same token's owner, get the first answer again, with an
     * Idempotent-Replayed: true header. While the first is still being worked
     * on the answer is 409; the same key with a different request is 422. Only
     * a successful answer is kept, so a refused request lets its key go and
     * can be corrected and sent again under it.
     */
    private function idempotent(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $key = trim($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');

        if (static::OWN_IDEMPOTENCY || $key === '' || !in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) {
            $this->error('Idempotency-Key must be 8-64 characters of A-Z, a-z, 0-9, _ or -.', 422);
        }

        $owner = $this->user ? 'u' . $this->user['id'] : 'a' . $this->apiUser['id'];
        $requestHash = hash('sha256', $method . ' ' . $this->requestPath() . "\n" . file_get_contents('php://input'));
        $keys = new IdempotencyKeyModel();
        $stored = $keys->claim($owner, $key, $requestHash);

        if ($stored !== null) {
            if (!hash_equals((string) $stored['request_hash'], $requestHash)) {
                $this->error('This Idempotency-Key was already used for a different request.', 422);
            }

            if ($stored['status_code'] !== null) {
                header('Idempotent-Replayed: true');
                $this->json(json_decode((string) $stored['response'], true) ?? [], (int) $stored['status_code']);
            }

            if (!(int) $stored['abandoned'] || !$keys->takeOver($owner, $key)) {
                header('Retry-After: 1');
                $this->error('The first request with this Idempotency-Key is still being processed. Try again in a moment.', 409);
            }
        }

        $this->idempotency = [$owner, $key];

        // Whatever ends the request before a successful answer — a refusal,
        // a fault — lets the key go: nothing was done under it.
        register_shutdown_function(function () use ($keys, $owner, $key): void {
            if ($this->idempotency === [$owner, $key]) {
                $keys->release($owner, $key);
            }
        });
    }

    /**
     * authenticate(), plus 403 for integration tokens: for endpoints whose
     * records must name the person who made them (stock movements).
     */
    protected function requireUser(): void
    {
        $this->authenticate();

        if (!$this->user) {
            $this->error('This endpoint needs a user token. Sign in with POST /api/auth/login.', 403);
        }
    }

    /**
     * requireUser(), plus the permission check: a user token acts with its
     * user's role, the same as in the web UI.
     */
    protected function requireUserPermission(string $permission): void
    {
        $this->requireUser();

        if (!\Cloudexus\Core\Acl::userCan((int) $this->user['id'], $permission)) {
            \Cloudexus\Core\AuditLog::record(
                \Cloudexus\Core\AuditLog::DENIED,
                'permission',
                null,
                $permission,
                'api',
                ['id' => (int) $this->user['id'], 'name' => (string) $this->user['full_name']]
            );
            $this->error('Your role does not allow this: ' . $permission . '.', 403);
        }
    }

    /** Writes this request's api_request_logs row; the status is filled in at shutdown. */
    protected function startLog(): ApiRequestLogModel
    {
        $log = new ApiRequestLogModel();
        $logId = $log->start(
            $this->apiUser['id'] ?? null,
            $this->user['id'] ?? null,
            $_SERVER['REQUEST_METHOD'] ?? '',
            $this->requestPath(),
            $this->clientIp()
        );
        $this->registerLogFinish($log, $logId);

        return $log;
    }

    protected function requestPath(): string
    {
        return strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    }

    protected function clientIp(): string
    {
        return ClientIp::get();
    }

    protected function userTokenLifetimeDays(): int
    {
        return max(1, (int) Config::get('api.user_token_lifetime_days', 90));
    }

    /** Fills in the log row's status code and duration once the response is final. */
    private function registerLogFinish(ApiRequestLogModel $log, int $logId): void
    {
        $start = microtime(true);
        register_shutdown_function(static function () use ($log, $logId, $start): void {
            $log->finish($logId, http_response_code() ?: 0, (microtime(true) - $start) * 1000);
        });
    }

    /**
     * Optional ?language=<code> selects the language translatable text is
     * returned in. Empty or unknown falls back to the default language, and a
     * record with no translation falls back to the default language too.
     */
    private function applyLanguage(): void
    {
        $requested = strtolower(trim($_GET['language'] ?? ''));
        if ($requested === '') {
            return;
        }

        if (!in_array($requested, Language::codes(), true)) {
            $this->error('Unknown language code: ' . $requested . '. See GET /api/languages.', 422);
        }

        Language::init($requested);
    }

    protected function bearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if ($header !== '' && preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            return trim($m[1]);
        }
        // Fallback for environments where the Authorization header is stripped.
        return trim($_SERVER['HTTP_X_API_KEY'] ?? '');
    }

    protected function json(array $data, int $status = 200): never
    {
        // A successful answer to a change sent with an Idempotency-Key is kept for its resends.
        if ($this->idempotency !== null && $status < 400) {
            [$owner, $key] = $this->idempotency;
            (new IdempotencyKeyModel())->complete($owner, $key, $status, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->idempotency = null;
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** @param array $details Optional machine-readable detail, e.g. which lines are short of stock. */
    protected function error(string $message, int $status = 400, array $details = []): never
    {
        $error = ['status' => $status, 'message' => $message];
        if ($details) {
            $error['details'] = $details;
        }
        $this->json(['error' => $error], $status);
    }

    /** Decoded JSON request body as an array. */
    protected function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->error('Invalid JSON request body.', 422);
        }
        return $data;
    }

    protected function perPage(): int
    {
        $pp = (int) ($_GET['per_page'] ?? self::DEFAULT_PER_PAGE);
        if ($pp < 1) {
            $pp = self::DEFAULT_PER_PAGE;
        }
        return min($pp, self::MAX_PER_PAGE);
    }

    protected function paginator(): Paginator
    {
        return new Paginator($this->perPage());
    }

    /** Outputs a page of rows plus pagination metadata. */
    protected function collection(array $rows, Paginator $pager): never
    {
        $this->json([
            'data' => array_values($rows),
            'meta' => [
                'page' => $pager->page,
                'per_page' => $pager->perPage,
                'total' => $pager->total,
                'total_pages' => $pager->pages(),
                'currency' => $this->currencyMeta(),
                'language' => $this->languageMeta(),
            ],
        ]);
    }

    /** Outputs a single resource plus the currency and language metadata. */
    protected function resource(array $data, int $status = 200): never
    {
        $this->json([
            'data' => $data,
            'meta' => [
                'currency' => $this->currencyMeta(),
                'language' => $this->languageMeta(),
            ],
        ], $status);
    }

    /**
     * The language translatable text in the response is returned in. Records
     * without a translation fall back to the default language.
     *
     * @return array{code: string, name: string, default: string}
     */
    protected function languageMeta(): array
    {
        return [
            'code' => Language::code(),
            'name' => (string) Language::current()['name'],
            'default' => Language::defaultCode(),
        ];
    }

    /**
     * The primary currency every monetary amount in the response is expressed
     * in. Amounts are never converted; see GET /api/currencies for the rates.
     *
     * @return array{code: string, symbol: string, title: string}
     */
    protected function currencyMeta(): array
    {
        $primary = Currency::primary();

        return [
            'code' => (string) $primary['code'],
            'symbol' => Currency::symbol(),
            'title' => (string) ($primary['title'] ?? ''),
        ];
    }

    /** Common list filter values (q + updated_since) read from the query string. */
    protected function baseFilters(): array
    {
        return [
            'q' => trim($_GET['q'] ?? ''),
            'updated_since' => trim($_GET['updated_since'] ?? ''),
        ];
    }
}
