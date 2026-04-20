<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

use Magento\Framework\App\ResourceConnection;
use Panth\Redirects\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Logs 404s into `panth_seo_404_log` with hit counter and last_seen timestamp.
 *
 * Uses `INSERT ... ON DUPLICATE KEY UPDATE` on (store_id, path_hash) so a
 * single row per (store, path) is kept regardless of how often the 404 fires.
 *
 * SECURITY HARDENING
 * ------------------
 * 1. All values are bound via parameter placeholders — never string-concat —
 *    so user-supplied referer / UA / path strings can never inject SQL.
 * 2. The path_hash comes from sha256(store_id|path), so a pathological path
 *    can never break the unique-key lookup.
 * 3. Per-IP rate limiting is applied in-process: we keep a micro-scale
 *    counter in an APCu bucket (or static array fallback) so an attacker
 *    hammering a single 404 URL cannot saturate the log table with inserts.
 */
class NotFoundLogger
{
    /** Keep a tiny in-process bucket for the fallback path when APCu is missing. */
    private static array $fallbackBuckets = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function log(string $requestPath, int $storeId, ?string $referer = null, ?string $userAgent = null): void
    {
        $path = $this->normalize($requestPath);
        if ($path === '' || $path === '/') {
            return;
        }

        // Rate limit: drop silently if this IP has already inserted too many
        // rows this second. Uses APCu if available, else a process-local array.
        if (!$this->acquireRateSlot($storeId)) {
            return;
        }

        $hash = hash('sha256', $storeId . '|' . $path);

        try {
            $conn  = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_404_log');
            $now   = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $refererValue   = $referer !== null ? (string) substr($referer, 0, 1024) : '';
            $userAgentValue = $userAgent !== null ? (string) substr($userAgent, 0, 512) : '';
            $pathValue      = (string) substr($path, 0, 1024);

            // Parameterised statement — never build SQL from user strings.
            $conn->query(
                "INSERT INTO {$table} (store_id, request_path, path_hash, referer, user_agent, hit_count, first_seen_at, last_seen_at) "
                . "VALUES (?, ?, ?, ?, ?, 1, ?, ?) "
                . "ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen_at = VALUES(last_seen_at), "
                . "referer = IF(VALUES(referer) != '', VALUES(referer), referer), "
                . "user_agent = IF(VALUES(user_agent) != '', VALUES(user_agent), user_agent)",
                [$storeId, $pathValue, $hash, $refererValue, $userAgentValue, $now, $now]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthRedirects] 404 log failed: ' . $e->getMessage());
        }
    }

    /**
     * Returns false when the current (ip, store, second) bucket already has
     * `rate_limit_per_second` inserts this second. Uses APCu when available
     * (multi-process), falls back to a static array (per-worker) otherwise.
     */
    private function acquireRateSlot(int $storeId): bool
    {
        $limit = $this->config->getLog404RateLimit($storeId);
        if ($limit <= 0) {
            return true;
        }

        $ip     = $this->resolveClientIp();
        $second = (int) floor(microtime(true));
        $key    = 'panth_redirects_404_rate_' . $storeId . '_' . hash('sha256', $ip) . '_' . $second;

        if (function_exists('apcu_enabled') && @apcu_enabled()) {
            $success = false;
            $current = (int) @apcu_fetch($key, $success);
            if (!$success) {
                @apcu_store($key, 1, 2); // 2-second TTL is enough
                return true;
            }
            if ($current >= $limit) {
                return false;
            }
            @apcu_inc($key);
            return true;
        }

        // Fallback: per-worker static array with second-level expiry. This is
        // best-effort under php-fpm — each worker tracks its own bucket — but
        // is still sufficient to kill the pathological "one request, thousands
        // of insert attempts" case this defends against.
        $current = self::$fallbackBuckets[$key] ?? 0;
        if ($current >= $limit) {
            return false;
        }
        self::$fallbackBuckets[$key] = $current + 1;

        // Bound memory: drop any bucket that isn't for the current second.
        foreach (array_keys(self::$fallbackBuckets) as $existing) {
            $parts = explode('_', $existing);
            $ts    = (int) end($parts);
            if ($ts !== $second) {
                unset(self::$fallbackBuckets[$existing]);
            }
        }
        return true;
    }

    private function resolveClientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['HTTP_X_REAL_IP']       ?? '',
            $_SERVER['REMOTE_ADDR']          ?? '',
        ];
        foreach ($candidates as $raw) {
            $raw = (string) $raw;
            if ($raw === '') {
                continue;
            }
            $first = trim((string) explode(',', $raw)[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
        return '0.0.0.0';
    }

    private function normalize(string $path): string
    {
        $path = trim($path);
        $q = strpos($path, '?');
        if ($q !== false) {
            $path = substr($path, 0, $q);
        }
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        return $path;
    }
}
