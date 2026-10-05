<?php
declare(strict_types=1);

namespace Panth\Redirects\Block\Adminhtml;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class GenericBackButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function getButtonData(): array
    {
        return [
            'label'      => __('Back'),
            'on_click'   => sprintf("location.href = '%s';", $this->urlBuilder->getUrl('*/*/')),
            'class'      => 'back',
            'sort_order' => 10,
        ];
    }
}
