<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Model\Redirect;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use Panth\Redirects\Model\Redirect\Matcher;
use Panth\Redirects\Model\Redirect\PageCacheIdentity;
use Panth\Redirects\Model\Redirect\RuleCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RuleCacheTest extends TestCase
{
    private array $events = [];
    private array $cleaned = [];
    private array $warnings = [];

    private function ruleCache(bool $eventThrows = false): RuleCache
    {
        $this->events = [];
        $this->cleaned = [];
        $this->warnings = [];

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('clean')->willReturnCallback(function ($tags) {
            $this->cleaned[] = $tags;
            return true;
        });
        $cache->method('load')->willReturn('v');
        $cache->method('save')->willReturn(true);
        $cache->method('remove')->willReturn(true);
        $cache->method('getFrontend')->willReturn(null);

        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function ($name, $data) use ($eventThrows) {
            if ($eventThrows) {
                throw new \RuntimeException('varnish down');
            }
            $this->events[] = [$name, $data];
        });

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->warnings[] = $message;
        });

        return new RuleCache($cache, $events, $logger);
    }

    public function testCleaningTheRuleTableAlsoCleansThePageCache(): void
    {
        $this->assertTrue($this->ruleCache()->clean([Matcher::CACHE_TAG]));

        $this->assertSame([[Matcher::CACHE_TAG]], $this->cleaned);
        $this->assertCount(1, $this->events);
        [$name, $data] = $this->events[0];
        $this->assertSame('clean_cache_by_tags', $name);
        $this->assertInstanceOf(PageCacheIdentity::class, $data['object']);
        $this->assertSame(['FPC'], $data['object']->getIdentities());
    }

    public function testOtherTagsDoNotTouchThePageCache(): void
    {
        $this->ruleCache()->clean(['config']);
        $this->assertSame([['config']], $this->cleaned);
        $this->assertSame([], $this->events);
    }

    public function testPageCacheFailureIsLoggedAndTheRuleCacheIsStillCleaned(): void
    {
        $this->assertTrue($this->ruleCache(true)->clean([Matcher::CACHE_TAG]));
        $this->assertSame([[Matcher::CACHE_TAG]], $this->cleaned);
        $this->assertSame(['[PanthRedirects] page cache clean failed: varnish down'], $this->warnings);
    }

    public function testOtherOperationsAreDelegated(): void
    {
        $cache = $this->ruleCache();
        $this->assertSame('v', $cache->load('k'));
        $this->assertTrue($cache->save('d', 'k', ['t'], 60));
        $this->assertTrue($cache->remove('k'));
        $this->assertNull($cache->getFrontend());
    }
}
