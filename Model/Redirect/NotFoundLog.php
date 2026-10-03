<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

use Magento\Framework\Model\AbstractModel;
use Panth\Redirects\Model\ResourceModel\NotFoundLog as NotFoundLogResource;

class NotFoundLog extends AbstractModel
{
    protected $_idFieldName = 'log_id';
    protected $_eventPrefix = 'panth_redirects_404_log';

    protected function _construct(): void
    {
        $this->_init(NotFoundLogResource::class);
    }
}
