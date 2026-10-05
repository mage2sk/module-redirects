<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Cron;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\Redirects\Cron\NotFoundCluster;
use Panth\Redirects\Cron\RedirectCleanup;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Model\Redirect\Matcher;
use Panth\Redirects\Test\Unit\DbStubTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CronJobsTest extends TestCase
{
    use DbStubTrait;

    private array $cleaned = [];
    private array $logs = [];

    private function dateTime(): DateTime
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn($format = null, $input = null) => $input === null
                ? '2026-10-03 12:00:00'
                : gmdate('Y-m-d H:i:s', (int) $input)
        );
        return $dateTime;
    }

    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        foreach (['info', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(function ($m) use ($level) {
                $this->logs[] = [$level, (string) $m];
            });
        }
        return $logger;
    }

    private function cache(): CacheInterface
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('clean')->willReturnCallback(function ($tags) {
            $this->cleaned[] = $tags;
            return true;
        });
        return $cache;
    }

    private function cluster(array $config): NotFoundCluster
    {
        return new NotFoundCluster($this->resourceStub($this->connectionStub($config)), $this->dateTime(), $this->logger());
    }

    public function testClusterGroupsNumericVariantsPerStore(): void
    {
        $this->cluster([
            'isTableExists' => true,
            'fetchAll'      => [
                ['request_path' => '/Product/123?ref=x', 'store_id' => '1', 'hits' => '5'],
                ['request_path' => '/product/456', 'store_id' => '1', 'hits' => '3'],
                ['request_path' => '/product/789', 'store_id' => '2', 'hits' => '1'],
            ],
        ])->execute();

        $this->assertSame([['table' => 'pfx_panth_seo_404_cluster', 'where' => '']], $this->deletes);
        $this->assertCount(2, $this->inserts);
        $this->assertSame([
            'pattern'    => '/product/{n}',
            'store_id'   => 1,
            'hits'       => 8,
            'sample_url' => '/Product/123?ref=x',
            'created_at' => '2026-10-03 12:00:00',
        ], $this->inserts[0]['data']);
        $this->assertSame(2, $this->inserts[1]['data']['store_id']);
        $this->assertSame(1, $this->inserts[1]['data']['hits']);
    }

    public function testClusterLooksBackSevenDays(): void
    {
        $this->cluster(['isTableExists' => true, 'fetchAll' => []])->execute();

        $since = strtotime((string) $this->whereValue('last_seen_at >= ?'));
        $this->assertEqualsWithDelta(time() - 7 * 86400, $since, 120);
        $this->assertCount(1, $this->deletes);
        $this->assertSame([], $this->inserts);
    }

    public function testClusterDoesNothingWhenTablesAreMissing(): void
    {
        $this->cluster(['isTableExists' => false])->execute();
        $this->assertSame([], $this->deletes);
        $this->assertSame([], $this->whereLog);
    }

    public function testClusterFailureIsLogged(): void
    {
        $this->cluster(['isTableExists' => true, 'fetchAll' => [], 'deleteThrows' => new \RuntimeException('locked')])
            ->execute();
        $this->assertSame([['error', '[PanthRedirects] NotFoundCluster failed: locked']], $this->logs);
    }

    private function cleanup(array $connectionConfig, int $expiryDays = 30): RedirectCleanup
    {
        $config = $this->createStub(Config::class);
        $config->method('getExpiryDays')->willReturn($expiryDays);
        return new RedirectCleanup(
            $this->resourceStub($this->connectionStub($connectionConfig)),
            $this->dateTime(),
            $config,
            $this->logger(),
            $this->cache()
        );
    }

    public function testCleanupRemovesExpiredAndStaleAutoRedirectsOnly(): void
    {
        $this->cleanup(['isTableExists' => true, 'deleteResult' => [2, 3]])->execute();

        $this->assertCount(2, $this->deletes);
        $this->assertSame([
            'finish_at IS NOT NULL',
            'finish_at < ?'         => '2026-10-03 12:00:00',
            'is_auto_generated = ?' => 1,
        ], $this->deletes[0]['where']);

        $stale = $this->deletes[1]['where'];
        $this->assertSame('hit_count = 0', $stale[0]);
        $this->assertSame(1, $stale['is_auto_generated = ?']);
        $this->assertEqualsWithDelta(time() - 30 * 86400, strtotime($stale['created_at < ?'] . ' UTC'), 120);

        $this->assertSame([[Matcher::CACHE_TAG]], $this->cleaned);
        $this->assertSame(
            ['info', '[PanthRedirects] Cleanup: removed 2 expired, 3 stale (unused > 30 days). Total: 5.'],
            $this->logs[0]
        );
    }

    public function testCleanupWithNothingRemovedLeavesCacheAlone(): void
    {
        $this->cleanup(['isTableExists' => true, 'deleteResult' => 0])->execute();
        $this->assertSame([], $this->cleaned);
        $this->assertSame([], $this->logs);
    }

    public function testCleanupSkipsMissingTable(): void
    {
        $this->cleanup(['isTableExists' => false])->execute();
        $this->assertSame([], $this->deletes);
    }

    public function testCleanupFailureIsLogged(): void
    {
        $this->cleanup(['isTableExists' => true, 'deleteThrows' => new \RuntimeException('nope')])->execute();
        $this->assertSame([['error', '[PanthRedirects] Cleanup failed: nope']], $this->logs);
        $this->assertSame([], $this->cleaned);
    }
}
