<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * HTTP status codes supported by the redirect rule engine. Shared by the
 * admin form dropdown, Save controller validator and CSV import so there
 * is a single source of truth for which codes are accepted.
 */
class StatusCode implements OptionSourceInterface
{
    public const ALLOWED = [301, 302, 303, 307, 308, 410, 451, 503];

    /**
     * @return array<int, array{value:int, label:string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 301, 'label' => (string) __('301 (Moved Permanently)')],
            ['value' => 302, 'label' => (string) __('302 (Found — Temporary)')],
            ['value' => 303, 'label' => (string) __('303 (See Other)')],
            ['value' => 307, 'label' => (string) __('307 (Temporary Redirect)')],
            ['value' => 308, 'label' => (string) __('308 (Permanent Redirect)')],
            ['value' => 410, 'label' => (string) __('410 (Gone)')],
            ['value' => 451, 'label' => (string) __('451 (Unavailable For Legal Reasons)')],
            ['value' => 503, 'label' => (string) __('503 (Service Unavailable)')],
        ];
    }
}
