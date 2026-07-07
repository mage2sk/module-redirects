<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

use Magento\Framework\App\ResourceConnection;
use Panth\Redirects\Helper\Config;
use Psr\Log\LoggerInterface;

class NotFoundLogger
{
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
                @apcu_store($key, 1, 2);
                return true;
            }
            if ($current >= $limit) {
                return false;
            }
            @apcu_inc($key);
            return true;
        }

        $current = self::$fallbackBuckets[$key] ?? 0;
        if ($current >= $limit) {
            return false;
        }
        self::$fallbackBuckets[$key] = $current + 1;

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
