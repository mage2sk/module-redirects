<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Panth\Redirects\Api\Data\RedirectRuleInterface;
use Panth\Redirects\Api\RedirectMatcherInterface;
use Panth\Redirects\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Two-tier redirect matcher:
 *  - Tier 1: hash table for literal path lookups (O(1))
 *  - Tier 2: compiled regex list (priority-ordered)
 *
 * Loaded once per request and cached in memory AND in Magento cache
 * (tag-invalidated on save/delete).
 *
 * SECURITY — REGEX COMPILATION
 * ----------------------------
 * The `pattern` column is admin-editable. A malformed regex would normally
 * cause `preg_match` to emit a warning and return false; under certain
 * `error_reporting` settings that can convert to an ErrorException and
 * crash the storefront. Every regex match here is wrapped in:
 *   1. @preg_match()   — suppress the PHP warning
 *   2. try { ... } catch (\Throwable)  — swallow any converted exception
 *   3. false-return check                — skip the row if invalid
 * so a single bad regex in the table can never 500 the site.
 */
class Matcher implements RedirectMatcherInterface
{
    public const CACHE_TAG = 'panth_redirects_table';
    private const CACHE_KEY_PREFIX = 'panth_redirects_table_';

    /** @var array<int,array{literal:array<string,array<string,mixed>>,regex:array<int,array<string,mixed>>}> */
    private array $memo = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly RedirectModelFactory $redirectFactory,
        private readonly NotFoundLogger $notFoundLogger,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function match(string $requestPath, int $storeId): ?RedirectRuleInterface
    {
        $table = $this->loadTable($storeId);
        $normalized = $this->normalize($requestPath);

        if (isset($table['literal'][$normalized])) {
            $row = $table['literal'][$normalized];
            if ($this->isWithinDateRange($row)) {
                $result = $this->hydrate($row);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        foreach ($table['regex'] as $row) {
            $pattern = $this->compile((string) $row['pattern']);
            if ($pattern === null) {
                continue;
            }

            $matched = false;
            try {
                $matched = @preg_match($pattern, $normalized) === 1;
            } catch (\Throwable $e) {
                $this->logger->warning('[PanthRedirects] regex match failed: ' . $e->getMessage(), [
                    'pattern' => $row['pattern'] ?? '',
                ]);
                continue;
            }
            if (!$matched) {
                continue;
            }

            if (!$this->isWithinDateRange($row)) {
                continue;
            }

            // Expand backreferences in target safely.
            $target = (string) $row['target'];
            try {
                $expanded = @preg_replace($pattern, $target, $normalized);
            } catch (\Throwable $e) {
                $this->logger->warning('[PanthRedirects] regex replace failed: ' . $e->getMessage(), [
                    'pattern' => $row['pattern'] ?? '',
                ]);
                continue;
            }
            if (is_string($expanded) && $expanded !== '') {
                $row['target'] = $expanded;
            }
            $result = $this->hydrate($row);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    public function recordHit(int $redirectId): void
    {
        try {
            $conn = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_redirect');
            $conn->update(
                $table,
                [
                    'hit_count'   => new \Zend_Db_Expr('hit_count + 1'),
                    'last_hit_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                ],
                ['redirect_id = ?' => $redirectId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthRedirects] recordHit failed: ' . $e->getMessage());
        }
    }

    public function log404(string $requestPath, int $storeId, ?string $referer = null, ?string $userAgent = null): void
    {
        if (!$this->config->isLog404Enabled($storeId)) {
            return;
        }
        $this->notFoundLogger->log($requestPath, $storeId, $referer, $userAgent);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isWithinDateRange(array $row): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $startAt  = $row['start_at'] ?? null;
        $finishAt = $row['finish_at'] ?? null;

        if ($startAt !== null && $startAt !== '') {
            try {
                $start = new \DateTimeImmutable((string) $startAt, new \DateTimeZone('UTC'));
                if ($now < $start) {
                    return false;
                }
            } catch (\Throwable) {
                // Invalid date — ignore the constraint.
            }
        }

        if ($finishAt !== null && $finishAt !== '') {
            try {
                $finish = new \DateTimeImmutable((string) $finishAt, new \DateTimeZone('UTC'));
                if ($now > $finish) {
                    return false;
                }
            } catch (\Throwable) {
                // Invalid date — ignore the constraint.
            }
        }

        return true;
    }

    /**
     * @return array{literal:array<string,array<string,mixed>>,regex:array<int,array<string,mixed>>}
     */
    private function loadTable(int $storeId): array
    {
        if (isset($this->memo[$storeId])) {
            return $this->memo[$storeId];
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $storeId;
        $cached   = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            try {
                /** @var array{literal:array,regex:array} $decoded */
                $decoded = $this->serializer->unserialize($cached);
                if (is_array($decoded) && isset($decoded['literal'], $decoded['regex'])) {
                    return $this->memo[$storeId] = $decoded;
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_redirect');
        $select = $conn->select()
            ->from($table)
            ->where('is_active = ?', 1)
            ->where('store_id IN (?)', [0, $storeId])
            ->order(['priority ASC', 'redirect_id ASC']);

        $literal = [];
        $regex   = [];
        foreach ($conn->fetchAll($select) as $row) {
            $matchType = (string) ($row['match_type'] ?? RedirectRuleInterface::MATCH_LITERAL);
            if ($matchType === RedirectRuleInterface::MATCH_LITERAL) {
                $literal[$this->normalize((string) $row['pattern'])] = $row;
            } else {
                $regex[] = $row;
            }
        }

        $data = ['literal' => $literal, 'regex' => $regex];
        try {
            $this->cache->save(
                $this->serializer->serialize($data),
                $cacheKey,
                [self::CACHE_TAG],
                3600
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthRedirects] redirect cache save failed: ' . $e->getMessage());
        }

        return $this->memo[$storeId] = $data;
    }

    private function normalize(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        $q = strpos($path, '?');
        if ($q !== false) {
            $path = substr($path, 0, $q);
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        if (strlen($path) > 1 && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    /**
     * Compile a user-supplied regex pattern. Returns the compiled PCRE
     * expression or null if the pattern is invalid / unsafe.
     *
     * The compilation itself is guarded with @-suppression and try/catch
     * so a malformed pattern only ever logs, never crashes.
     */
    private function compile(string $pattern): ?string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return null;
        }
        // If user supplies ~..~ or /../ treat as pre-delimited, else wrap in ~...~
        if (@preg_match('/^([~\/#!@%]).*\1[a-zA-Z]*$/s', $pattern)) {
            $compiled = $pattern;
        } else {
            $compiled = '~' . str_replace('~', '\\~', $pattern) . '~';
        }
        // Validate that the compiled pattern is actually parseable by PCRE.
        try {
            $test = @preg_match($compiled, '');
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthRedirects] regex compile threw: ' . $e->getMessage(), [
                'pattern' => $pattern,
            ]);
            return null;
        }
        if ($test === false) {
            $this->logger->warning('[PanthRedirects] skipping unparseable regex', [
                'pattern' => $pattern,
            ]);
            return null;
        }
        return $compiled;
    }

    private function sanitizeTarget(string $target): ?string
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }
        if (@preg_match('#^(javascript|data|vbscript):#i', $target)) {
            return null;
        }
        return $target;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): ?RedirectRuleInterface
    {
        $target = $this->sanitizeTarget((string) ($row['target'] ?? ''));
        if ($target === null) {
            $this->logger->warning('[PanthRedirects] Blocked unsafe redirect target', [
                'pattern' => $row['pattern'] ?? '',
                'target'  => $row['target'] ?? '',
            ]);
            return null;
        }
        $row['target'] = $target;
        $model = $this->redirectFactory->create();
        $model->setData($row);
        return $model;
    }
}
