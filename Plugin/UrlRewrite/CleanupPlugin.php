<?php
declare(strict_types=1);

namespace Panth\Redirects\Plugin\UrlRewrite;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\UrlRewrite\Model\ResourceModel\UrlRewrite as UrlRewriteResource;
use Magento\UrlRewrite\Model\UrlRewrite as UrlRewriteModel;
use Panth\Redirects\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Watches UrlRewrite saves for slug changes on products and writes a
 * corresponding row into `panth_seo_redirect` so the Matcher picks up the
 * 301 automatically. Dedupes on (store_id, pattern) so repeated slug
 * changes keep refreshing the target instead of piling up chains.
 */
class CleanupPlugin
{
    private const REDIRECT_TABLE = 'panth_seo_redirect';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    public function beforeSave(UrlRewriteResource $subject, \Magento\Framework\Model\AbstractModel $object): array
    {
        try {
            if (!$this->config->isEnabled()) {
                return [$object];
            }
            if (!$object instanceof UrlRewriteModel || !$object->getId()) {
                return [$object];
            }
            if ((string) $object->getEntityType() !== 'product') {
                return [$object];
            }
            $original = $object->getOrigData('request_path');
            $new      = $object->getRequestPath();
            if ($original === null || $original === '' || $original === $new) {
                return [$object];
            }
            $this->recordRedirect(
                (int) $object->getStoreId(),
                (string) $original,
                (string) $new
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthRedirects] urlrewrite cleanup failed', ['error' => $e->getMessage()]);
        }
        return [$object];
    }

    private function recordRedirect(int $storeId, string $from, string $to): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::REDIRECT_TABLE);

        $existing = $connection->fetchOne(
            $connection->select()
                ->from($table, ['redirect_id'])
                ->where('store_id = ?', $storeId)
                ->where('pattern = ?', $from)
                ->where('match_type = ?', 'literal')
                ->limit(1)
        );

        $now = $this->dateTime->gmtDate();
        if ($existing) {
            $connection->update(
                $table,
                ['target' => $to, 'is_active' => 1, 'updated_at' => $now],
                ['redirect_id = ?' => (int) $existing]
            );
            return;
        }

        $connection->insert($table, [
            'store_id'          => $storeId,
            'match_type'        => 'literal',
            'pattern'           => $from,
            'target'            => $to,
            'status_code'       => 301,
            'priority'          => 10,
            'is_active'         => 1,
            'hit_count'         => 0,
            'is_auto_generated' => 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
    }
}
