<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Model\Redirect;

use Panth\Redirects\Model\Redirect\PatternCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PatternCompilerTest extends TestCase
{
    private PatternCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new PatternCompiler();
    }

    public static function compileProvider(): array
    {
        return [
            'plain is wrapped'          => ['^/archive/([0-9]+)$', '~^/archive/([0-9]+)$~'],
            'tilde is escaped'          => ['^/a~b$', '~^/a\~b$~'],
            'delimited kept'            => ['#^/SHOP/(.+)$#i', '#^/SHOP/(.+)$#i'],
            'slash delimited kept'      => ['/old-page/', '/old-page/'],
            'surrounding space trimmed' => ['  ^/x$  ', '~^/x$~'],
        ];
    }

    #[DataProvider('compileProvider')]
    public function testCompile(string $pattern, string $expected): void
    {
        $this->assertSame($expected, $this->compiler->compile($pattern));
    }

    public static function brokenProvider(): array
    {
        return [
            'empty'                    => [''],
            'blank'                    => ['   '],
            'unbalanced group'         => ['^/a(['],
            'delimited with bad flags' => ['/blog/.*/'],
            'removed e modifier'       => ['/x/e'],
        ];
    }

    #[DataProvider('brokenProvider')]
    public function testBrokenPatternsDoNotCompile(string $pattern): void
    {
        $this->assertNull($this->compiler->compile($pattern));
    }

    public function testValidPatternHasNoError(): void
    {
        $this->assertNull($this->compiler->validate('^/archive/([0-9]+)$'));
        $this->assertNull($this->compiler->validate('^/blog/(.*)$'));
        $this->assertNull($this->compiler->validate('#^/SHOP/(.+)$#i'));
    }

    public function testBrokenPatternReportsInvalidRegex(): void
    {
        $this->assertStringStartsWith(
            'Pattern is not a valid regular expression.',
            (string) $this->compiler->validate('/blog/.*/')
        );
    }

    public static function catastrophicProvider(): array
    {
        return [
            'nested plus'          => ['^/sale/(a+)+$'],
            'nested star'          => ['^/(a*)*$'],
            'alternation overlap'  => ['^/p/(a|aa)+$'],
            'digits nested'        => ['^/(\d+)*$'],
        ];
    }

    #[DataProvider('catastrophicProvider')]
    public function testCatastrophicPatternsAreRejected(string $pattern): void
    {
        $this->assertStringStartsWith(
            'Pattern is too expensive to evaluate',
            (string) $this->compiler->validate($pattern)
        );
    }

    public function testValidationLeavesNoErrorHandlerOrPcreErrorBehind(): void
    {
        $this->compiler->validate('^/a([');
        $this->compiler->validate('^/sale/(a+)+$');
        $this->assertSame(1, preg_match('/ok/', 'ok'));
        $this->assertSame(PREG_NO_ERROR, preg_last_error());
    }

    public static function expandProvider(): array
    {
        return [
            'dollar'              => ['/product/$1', ['/archive/42', '42'], '/product/42'],
            'braced'              => ['/p/${1}0', ['/x/7', '7'], '/p/70'],
            'two digits'          => ['/p/$10', array_merge(['x'], array_fill(0, 10, 'g')), '/p/g'],
            'missing group empty' => ['/p/$3', ['/x', 'a'], '/p/'],
            'no references'       => ['/contact', ['/foo/old-bar'], '/contact'],
            'whole match'         => ['/copy$0', ['/abc'], '/copy/abc'],
        ];
    }

    #[DataProvider('expandProvider')]
    public function testExpandTarget(string $target, array $groups, string $expected): void
    {
        $this->assertSame($expected, $this->compiler->expandTarget($target, $groups));
    }
}
