<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\Redirects\Helper\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private array $calls = [];

    private function config(array $values = [], array $flags = []): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            function ($path, $scopeType = null, $scopeCode = null) use ($values) {
                $this->calls[] = [$path, $scopeType, $scopeCode];
                return $values[$path] ?? null;
            }
        );
        $scope->method('isSetFlag')->willReturnCallback(
            function ($path, $scopeType = null, $scopeCode = null) use ($flags) {
                $this->calls[] = [$path, $scopeType, $scopeCode];
                return (bool) ($flags[$path] ?? false);
            }
        );
        return new Config($scope);
    }

    public static function flagProvider(): array
    {
        return [
            'enabled'        => [Config::XML_GENERAL_ENABLED, 'isEnabled'],
            'auto redirect'  => [Config::XML_AUTO_REDIRECT_ENABLED, 'isAutoRedirectEnabled'],
            'lowercase'      => [Config::XML_LOWERCASE_REDIRECT, 'isLowercaseRedirectEnabled'],
            'homepage'       => [Config::XML_HOMEPAGE_REDIRECT, 'isHomepageRedirectEnabled'],
            'trailing slash' => [Config::XML_REMOVE_TRAILING_SLASH, 'canonicalRemoveTrailingSlash'],
            'log 404'        => [Config::XML_LOG_404, 'isLog404Enabled'],
            'match original' => [Config::XML_MATCH_ORIGINAL_URI, 'isMatchOriginalUriEnabled'],
        ];
    }

    #[DataProvider('flagProvider')]
    public function testFlagsReadTheirOwnPathAtStoreScope(string $path, string $method): void
    {
        $this->assertTrue($this->config([], [$path => true])->{$method}(3));
        $this->assertSame([$path, ScopeInterface::SCOPE_STORE, 3], end($this->calls));
        $this->assertFalse($this->config()->{$method}(3));
    }

    public function testStrategyDefaultsToParentCategory(): void
    {
        $this->assertSame('parent_category', $this->config()->getAutoRedirectTargetStrategy());
        $this->assertSame(
            'homepage',
            $this->config([Config::XML_AUTO_REDIRECT_STRATEGY => 'homepage'])->getAutoRedirectTargetStrategy()
        );
    }

    public function testCustomUrlDefaultsToEmptyString(): void
    {
        $this->assertSame('', $this->config()->getAutoRedirectCustomUrl());
        $this->assertSame(
            '/sale',
            $this->config([Config::XML_AUTO_REDIRECT_CUSTOM_URL => '/sale'])->getAutoRedirectCustomUrl(1)
        );
    }

    public static function positiveIntProvider(): array
    {
        return [
            'unset uses default'    => [null, null, 365, 10],
            'positive values kept'  => ['30', '5', 30, 5],
            'zero uses default'     => ['0', '0', 365, 10],
            'negative uses default' => ['-4', '-1', 365, 10],
        ];
    }

    #[DataProvider('positiveIntProvider')]
    public function testExpiryDaysAndRateLimitFallBackForNonPositiveValues(
        ?string $days,
        ?string $limit,
        int $expectedDays,
        int $expectedLimit
    ): void {
        $config = $this->config([
            Config::XML_EXPIRY_DAYS        => $days,
            Config::XML_LOG_404_RATE_LIMIT => $limit,
        ]);
        $this->assertSame($expectedDays, $config->getExpiryDays());
        $this->assertSame($expectedLimit, $config->getLog404RateLimit());
    }

    public function testRegexLimitsAreClampedAtZero(): void
    {
        $config = $this->config([
            Config::XML_REGEX_MAX_EVALUATIONS => '-5',
            Config::XML_REGEX_BACKTRACK_LIMIT => '200000',
        ]);
        $this->assertSame(0, $config->getRegexMaxEvaluations());
        $this->assertSame(200000, $config->getRegexBacktrackLimit());
        $this->assertSame(0, $this->config()->getRegexBacktrackLimit());
    }

    public function testGetValuePassesThroughRawValue(): void
    {
        $this->assertSame('x', $this->config(['some/path' => 'x'])->getValue('some/path', 2));
        $this->assertNull($this->config()->getValue('missing/path'));
    }
}
