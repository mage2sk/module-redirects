<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class NotFoundCluster extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_seo_404_cluster', 'cluster_id');
    }
}
