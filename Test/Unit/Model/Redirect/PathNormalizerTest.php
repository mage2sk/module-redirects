<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Model\Redirect;

use Panth\Redirects\Model\Redirect\PathNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PathNormalizerTest extends TestCase
{
    private PathNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new PathNormalizer();
    }

    public static function paths(): array
    {
        return [
            'plain path' => ['/old-page', '/old-page'],
            'missing leading slash' => ['old-page', '/old-page'],
            'trailing slash' => ['/old-page/', '/old-page'],
            'query string' => ['/old-page?ref=news', '/old-page'],
            'fragment' => ['/old-page#section', '/old-page'],
            'query and fragment' => ['/old-page?a=1#top', '/old-page'],
            'trailing slash with query' => ['/blog/old-slug/?ref=news', '/blog/old-slug'],
            'absolute url' => ['https://example.com/old-page', '/old-page'],
            'absolute url with query' => ['https://example.com/old-page?x=1', '/old-page'],
            'root' => ['/', '/'],
            'empty' => ['', '/'],
            'whitespace' => ['   ', '/'],
            'deep path' => ['/faq/item/some-slug/', '/faq/item/some-slug'],
            'case preserved' => ['/Blog/OLD-Slug', '/Blog/OLD-Slug'],
            'multiple trailing slashes' => ['/old-page///', '/old-page'],
        ];
    }

    #[DataProvider('paths')]
    public function testNormalize(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($input));
    }

    #[DataProvider('paths')]
    public function testNormalizeIsIdempotent(string $input, string $expected): void
    {
        $once = $this->normalizer->normalize($input);
        $this->assertSame($once, $this->normalizer->normalize($once));
    }
}
