<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\ResourceModel\NotFoundLog;

use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\Redirects\Model\Redirect\NotFoundLog as NotFoundLogModel;
use Panth\Redirects\Model\ResourceModel\NotFoundLog as NotFoundLogResource;

class Collection extends AbstractCollection implements SearchResultInterface
{
    protected $_idFieldName = 'log_id';

    /**
     * @var \Magento\Framework\Api\Search\AggregationInterface
     */
    private $aggregations;

    protected function _construct(): void
    {
        $this->_init(NotFoundLogModel::class, NotFoundLogResource::class);
    }

    public function getAggregations()
    {
        return $this->aggregations;
    }

    public function setAggregations($aggregations)
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    public function getSearchCriteria()
    {
        return null;
    }

    public function setSearchCriteria(SearchCriteriaInterface $searchCriteria = null)
    {
        return $this;
    }

    public function getTotalCount()
    {
        return $this->getSize();
    }

    public function setTotalCount($totalCount)
    {
        return $this;
    }

    public function setItems(array $items = null)
    {
        return $this;
    }
}
