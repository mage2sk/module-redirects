<?php
declare(strict_types=1);

namespace Panth\Redirects\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Custom column renderer that colour-codes HTTP status codes.
 *
 *  200        — green
 *  301 / 302  — yellow / orange
 *  404 / 5xx  — red
 */
class StatusCodeColumn extends Column
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
            $code  = (int) ($item[$fieldName] ?? 0);
            $color = $this->resolveColor($code);
            $item[$fieldName] = sprintf(
                '<span style="color:%s;font-weight:600;">%s</span>',
                $this->escaper->escapeHtmlAttr($color),
                $this->escaper->escapeHtml((string) $code)
            );
        }

        return $dataSource;
    }

    private function resolveColor(int $code): string
    {
        if ($code >= 200 && $code < 300) {
            return '#185b00';
        }
        if ($code >= 300 && $code < 400) {
            return '#b8860b';
        }
        if ($code >= 400 && $code < 500) {
            return '#e22626';
        }
        if ($code >= 500) {
            return '#e22626';
        }
        return '#333333';
    }
}
