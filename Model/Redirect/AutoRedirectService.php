<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Redirects\Api\Data\RedirectRuleInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared service for programmatically creating redirect rows in the
 * `panth_seo_redirect` table. Used by the auto-redirect observers when
 * products, categories or CMS pages are deleted.
 *
 * SECURITY
 * --------
 * The target is validated against the `isSafeTarget()` rules: no dangerous
 * URI schemes, no external hosts outside the configured store base URLs,
 * no `..` path traversal and no control characters. Unsafe targets are
 * logged and dropped instead of persisted, so an attacker-controlled
 * "custom URL" strategy value can never become a persistent open-redirect.
 */
class AutoRedirectService
{
    private const TABLE = 'panth_seo_redirect';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
        private readonly ?StoreManagerInterface $storeManager = null
    ) {
    }

    public function createRedirect(
        string $sourcePath,
        string $targetPath,
        int $storeId,
        int $statusCode = 301
    ): void {
        $sourcePath = $this->normalizePath($sourcePath);
        $targetPath = $this->normalizePath($targetPath);

        if ($sourcePath === '' || $targetPath === '' || $sourcePath === $targetPath) {
            return;
        }

        if (!$this->isSafeTarget($targetPath)) {
            $this->logger->warning(
                '[PanthRedirects] Auto-redirect rejected: unsafe target',
                ['source' => $sourcePath, 'target' => $targetPath, 'store_id' => $storeId]
            );
            return;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::TABLE);

            // Check for existing redirect with same pattern and store_id to avoid duplicates.
            $select = $connection->select()
                ->from($table, [RedirectRuleInterface::REDIRECT_ID])
                ->where(RedirectRuleInterface::PATTERN . ' = ?', $sourcePath)
                ->where(RedirectRuleInterface::STORE_ID . ' = ?', $storeId)
                ->limit(1);

            if ($connection->fetchOne($select) !== false) {
                $this->logger->debug(
                    '[PanthRedirects] Auto-redirect skipped (duplicate)',
                    ['source' => $sourcePath, 'target' => $targetPath, 'store_id' => $storeId]
                );
                return;
            }

            $connection->insert($table, [
                RedirectRuleInterface::PATTERN           => $sourcePath,
                RedirectRuleInterface::TARGET            => $targetPath,
                RedirectRuleInterface::STATUS_CODE       => $statusCode,
                RedirectRuleInterface::MATCH_TYPE        => RedirectRuleInterface::MATCH_LITERAL,
                RedirectRuleInterface::IS_ACTIVE         => 1,
                RedirectRuleInterface::STORE_ID          => $storeId,
                RedirectRuleInterface::IS_AUTO_GENERATED => 1,
            ]);

            $this->logger->info(
                '[PanthRedirects] Auto-redirect created',
                ['source' => $sourcePath, 'target' => $targetPath, 'store_id' => $storeId, 'status' => $statusCode]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                '[PanthRedirects] Failed to create auto-redirect',
                ['source' => $sourcePath, 'target' => $targetPath, 'error' => $e->getMessage()]
            );
        }
    }

    private function isSafeTarget(string $target): bool
    {
        if ($target === '' || $target === '/') {
            return true;
        }

        if (@preg_match('#^(javascript|data|vbscript|file):#i', $target)) {
            return false;
        }

        if (@preg_match('#^https?://#i', $target)) {
            if ($this->storeManager === null) {
                return false;
            }
            $host = parse_url($target, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                return false;
            }
            foreach ($this->storeManager->getStores(true) as $store) {
                $storeHost = parse_url((string) $store->getBaseUrl(), PHP_URL_HOST);
                if (is_string($storeHost) && strcasecmp($host, $storeHost) === 0) {
                    return true;
                }
            }
            return false;
        }

        if (str_starts_with($target, '//')) {
            return false;
        }

        foreach (explode('/', $target) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        if (@preg_match('/[\x00-\x1F\x7F]/', $target)) {
            return false;
        }

        return true;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B");
        if ($path === '/') {
            return '/';
        }
        return trim($path, '/');
    }
}
