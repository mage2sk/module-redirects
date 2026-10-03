<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Model\Redirect;

use Panth\Redirects\Model\Redirect\RegexPrefilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RegexPrefilterTest extends TestCase
{
    private RegexPrefilter $prefilter;

    protected function setUp(): void
    {
        $this->prefilter = new RegexPrefilter();
    }

    public static function analyses(): array
    {
        return [
            'anchored prefix' => ['~^/archive/([0-9]+)$~', 'prefix', '/archive/', false],
            'escaped slashes' => ['#^\/old\.html$#', 'prefix', '/old.html', false],
            'case insensitive' => ['~^/Blog/(.*)$~i', 'prefix', '/Blog/', true],
            'optional last char' => ['~^/items?/(\d+)$~', 'prefix', '/item', false],
            'plus quantifier' => ['~^/pa+ge$~', 'prefix', '/pa', false],
            'unanchored contains' => ['~/sale/.*-old$~', 'contains', '/sale/', false],
            'top level alternation' => ['~^/a|^/b~', 'none', '', false],
            'alternation inside group' => ['~^/(shoes|boots)/clearance$~', 'prefix', '/', false],
            'inline option' => ['~(?i)^/abc~', 'none', '', false],
            'extended modifier' => ['~^/abc~x', 'none', '', false],
            'hex escape' => ['~^/\x41bc~', 'none', '', false],
            'counted quantifier' => ['~^/ab{2}c~', 'prefix', '/a', false],
            'character class only' => ['~^[a-z]+$~', 'none', '', false],
            'multiline modifier' => ['~^/news/~m', 'contains', '/news/', false],
        ];
    }

    #[DataProvider('analyses')]
    public function testAnalyze(string $compiled, string $mode, string $needle, bool $ci): void
    {
        $result = $this->prefilter->analyze($compiled);
        $this->assertSame($mode, $result['mode']);
        if ($mode !== RegexPrefilter::MODE_NONE) {
            $this->assertSame($needle, $result['needle']);
            $this->assertSame($ci, $result['ci']);
        }
    }

    public static function subjects(): array
    {
        return [
            ['~^/archive/([0-9]+)$~', '/archive/42'],
            ['~^/archive/([0-9]+)$~', '/other/42'],
            ['~^/Blog/(.*)$~i', '/blog/post'],
            ['~^/items?/(\d+)$~', '/item/5'],
            ['~^/items?/(\d+)$~', '/items/5'],
            ['~^/pa+ge$~', '/paaage'],
            ['~/sale/.*-old$~', '/en/sale/shirt-old'],
            ['~^/(shoes|boots)/clearance$~', '/boots/clearance'],
            ['~^/ab{2}c~', '/abbc'],
            ['~^/ab*c~', '/ac'],
            ['~^/news/~m', '/news/today'],
            ['~^/x(?=y)yz~', '/xyz'],
            ['~^/a\+b~', '/a+b'],
            ['~^/[]a]x~', '/]x'],
        ];
    }

    #[DataProvider('subjects')]
    public function testNeverRejectsAMatchingSubject(string $compiled, string $subject): void
    {
        $filter = $this->prefilter->analyze($compiled);
        $matches = preg_match($compiled, $subject) === 1;
        if ($matches) {
            $this->assertTrue($this->prefilter->accepts($filter, $subject));
        } else {
            $this->assertIsBool($this->prefilter->accepts($filter, $subject));
        }
    }

    public function testRejectsPathsWithoutTheFixedText(): void
    {
        $filter = $this->prefilter->analyze('~^/archive/([0-9]+)$~');
        $this->assertFalse($this->prefilter->accepts($filter, '/catalog/product/view/id/1'));
        $this->assertTrue($this->prefilter->accepts($filter, '/archive/7'));
    }
}
