<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

use Magento\Framework\Model\AbstractModel;
use Panth\Redirects\Model\ResourceModel\NotFoundCluster as NotFoundClusterResource;

class NotFoundCluster extends AbstractModel
{
    protected $_idFieldName = 'cluster_id';
    protected $_eventPrefix = 'panth_redirects_404_cluster';

    protected function _construct(): void
    {
        $this->_init(NotFoundClusterResource::class);
    }
}
