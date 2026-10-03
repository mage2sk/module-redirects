<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Model\Redirect;

use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Model\Redirect\NotFoundLogger;
use Panth\Redirects\Test\Unit\DbStubTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NotFoundLoggerTest extends TestCase
{
    use DbStubTrait;

    private array $queries = [];
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->queries = [];
        $this->warnings = [];
        (new \ReflectionClass(NotFoundLogger::class))->setStaticPropertyValue('fallbackBuckets', []);
    }

    protected function tearDown(): void
    {
        (new \ReflectionClass(NotFoundLogger::class))->setStaticPropertyValue('fallbackBuckets', []);
    }

    private function logger(int $rateLimit = 100, ?\Throwable $queryError = null, bool $ipThrows = false): NotFoundLogger
    {
        $connection = $this->connectionStub([
            'query' => function ($sql, $bind = []) use ($queryError) {
                if ($queryError !== null) {
                    throw $queryError;
                }
                $this->queries[] = ['sql' => $sql, 'bind' => $bind];
                return null;
            },
        ]);

        $config = $this->createStub(Config::class);
        $config->method('getLog404RateLimit')->willReturn($rateLimit);

        $psr = $this->createStub(LoggerInterface::class);
        $psr->method('warning')->willReturnCallback(function ($m) {
            $this->warnings[] = (string) $m;
        });

        $remote = $this->createStub(RemoteAddress::class);
        if ($ipThrows) {
            $remote->method('getRemoteAddress')->willThrowException(new \RuntimeException('no ip'));
        } else {
            // A unique address per test keeps rate buckets from leaking between tests.
            $remote->method('getRemoteAddress')->willReturn('10.0.' . random_int(0, 255) . '.' . random_int(1, 254));
        }

        return new NotFoundLogger($this->resourceStub($connection), $config, $psr, $remote);
    }

    private function waitForFreshSecond(): void
    {
        while (fmod(microtime(true), 1.0) > 0.6) {
            usleep(20000);
        }
    }

    public function testHomepageAndEmptyPathsAreNeverLogged(): void
    {
        $logger = $this->logger();
        $logger->log('/', 1);
        $logger->log('', 1);
        $logger->log('?q=1', 1);

        $this->assertSame([], $this->queries);
    }

    public function testPathIsNormalisedAndHashedPerStore(): void
    {
        $this->logger()->log('missing/page/?utm=1', 2, 'https://ref.test/', 'Agent');

        $this->assertCount(1, $this->queries);
        $bind = $this->queries[0]['bind'];
        $this->assertSame(2, $bind[0]);
        $this->assertSame('/missing/page', $bind[1]);
        $this->assertSame(hash('sha256', '2|/missing/page'), $bind[2]);
        $this->assertSame('https://ref.test/', $bind[3]);
        $this->assertSame('Agent', $bind[4]);
        $this->assertSame($bind[5], $bind[6]);
        $this->assertStringContainsString('INSERT INTO pfx_panth_seo_404_log', $this->queries[0]['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE hit_count = hit_count + 1', $this->queries[0]['sql']);
    }

    public function testMissingRefererAndAgentAreStoredAsEmptyStrings(): void
    {
        $this->logger()->log('/gone', 1);

        $this->assertSame('', $this->queries[0]['bind'][3]);
        $this->assertSame('', $this->queries[0]['bind'][4]);
    }

    public function testLongValuesAreTruncatedToColumnSizes(): void
    {
        $this->logger()->log('/' . str_repeat('p', 2000), 1, str_repeat('r', 3000), str_repeat('u', 900));

        $bind = $this->queries[0]['bind'];
        $this->assertSame(1024, strlen($bind[1]));
        $this->assertSame(1024, strlen($bind[3]));
        $this->assertSame(512, strlen($bind[4]));
    }

    public function testRateLimitCapsWritesPerClientPerSecond(): void
    {
        $this->waitForFreshSecond();
        $logger = $this->logger(2);
        $logger->log('/a', 1);
        $logger->log('/b', 1);
        $logger->log('/c', 1);

        $this->assertSame(['/a', '/b'], array_map(static fn($q) => $q['bind'][1], $this->queries));
    }

    public function testRateLimitIsCountedPerStore(): void
    {
        $this->waitForFreshSecond();
        $logger = $this->logger(1);
        $logger->log('/a', 1);
        $logger->log('/a', 2);

        $this->assertCount(2, $this->queries);
    }

    public function testUnknownClientAddressStillLogs(): void
    {
        $this->logger(100, null, true)->log('/x', 1);
        $this->assertCount(1, $this->queries);
    }

    public function testDatabaseErrorsAreSwallowedAndLogged(): void
    {
        $this->logger(100, new \RuntimeException('deadlock'))->log('/x', 1);

        $this->assertSame(['[PanthRedirects] 404 log failed: deadlock'], $this->warnings);
    }
}
