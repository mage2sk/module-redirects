<?php
declare(strict_types=1);

namespace Panth\Redirects\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Render a freeform text cell (referer, user_agent, request path) via
 * `escapeHtml()`. Untrusted strings captured by the 404 logger must NEVER
 * reach the admin grid as raw HTML — an attacker could have submitted a
 * malformed referer that contains script tags or an `onerror=` attribute.
 */
class EscapeTextColumn extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $fieldName = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            if (!array_key_exists($fieldName, $item)) {
                continue;
            }
            $value = (string) ($item[$fieldName] ?? '');
            $item[$fieldName] = $this->escaper->escapeHtml($value);
        }

        return $dataSource;
    }
}
