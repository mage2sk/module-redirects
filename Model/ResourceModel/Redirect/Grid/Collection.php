<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\ResourceModel\Redirect\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Real PHP class (not a virtualType) so the UI listing DataProvider can be
 * resolved at compile time and the grid filters pick up the correct PK.
 */
class Collection extends SearchResult
{
    protected function _initSelect(): static
    {
        parent::_initSelect();
        return $this;
    }
}
