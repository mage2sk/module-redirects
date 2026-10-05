<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Model\Config;

use Panth\Redirects\Model\Config\Source\RedirectTargetStrategy;
use Panth\Redirects\Model\Config\Source\StatusCode;
use PHPUnit\Framework\TestCase;

class SourceModelsTest extends TestCase
{
    public function testStatusCodeOptionsMatchTheAllowedList(): void
    {
        $values = array_column((new StatusCode())->toOptionArray(), 'value');
        $this->assertSame(StatusCode::ALLOWED, $values);
    }

    public function testStatusCodeLabelsStartWithTheCode(): void
    {
        foreach ((new StatusCode())->toOptionArray() as $option) {
            $this->assertStringStartsWith((string) $option['value'], $option['label']);
        }
    }

    public function testStrategyValuesAreTheOnesTheProductObserverUnderstands(): void
    {
        $values = array_column((new RedirectTargetStrategy())->toOptionArray(), 'value');
        $this->assertSame(['parent_category', 'homepage', 'custom_url'], $values);
    }
}
