<?php
declare(strict_types=1);

namespace Panth\Redirects\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\Redirects\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Periodic redirect cleanup:
 *  1. Deletes redirects whose `finish_at` is in the past (expired schedule).
 *  2. Deletes auto-generated redirects older than `expiry_days` that have
 *     never been hit. Admin-curated rows are ALWAYS preserved.
 */
class RedirectCleanup
{
    private const TABLE = 'panth_seo_redirect';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        if (!$connection->isTableExists($table)) {
            return;
        }

        $now = $this->dateTime->gmtDate();
        $expiryDays = $this->config->getExpiryDays();
        $cutoff = $this->dateTime->gmtDate(
            null,
            strtotime(sprintf('-%d days', $expiryDays))
        );

        $totalCleaned = 0;

        try {
            $expiredCount = $connection->delete($table, [
                'finish_at IS NOT NULL',
                'finish_at < ?' => $now,
            ]);
            $totalCleaned += $expiredCount;

            $staleCount = $connection->delete($table, [
                'hit_count = 0',
                'is_auto_generated = ?' => 1,
                'created_at < ?' => $cutoff,
            ]);
            $totalCleaned += $staleCount;

            if ($totalCleaned > 0) {
                $this->logger->info(
                    sprintf(
                        '[PanthRedirects] Cleanup: removed %d expired, %d stale (unused > %d days). Total: %d.',
                        $expiredCount,
                        $staleCount,
                        $expiryDays,
                        $totalCleaned
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('[PanthRedirects] Cleanup failed: ' . $e->getMessage());
        }
    }
}
