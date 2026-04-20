<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Atomic hit-counter service for redirect rules.
 *
 * `hit_count = hit_count + 1` is an atomic UPDATE with no preceding SELECT,
 * so no lost increments are possible under concurrent traffic.
 */
class HitTracker
{
    private const TABLE = 'panth_seo_redirect';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function recordHit(int $redirectId): void
    {
        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::TABLE);

            $connection->update(
                $table,
                [
                    'hit_count'   => new \Zend_Db_Expr('hit_count + 1'),
                    'last_hit_at' => new \Zend_Db_Expr('NOW()'),
                ],
                ['redirect_id = ?' => $redirectId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PanthRedirects] HitTracker::recordHit failed: ' . $e->getMessage(),
                ['redirect_id' => $redirectId]
            );
        }
    }
}
