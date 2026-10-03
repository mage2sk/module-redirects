<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Model\Redirect;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Model\Redirect\Matcher;
use Panth\Redirects\Model\Redirect\NotFoundLogger;
use Panth\Redirects\Model\Redirect\RedirectModel;
use Panth\Redirects\Model\Redirect\RedirectModelFactory;
use Panth\Redirects\Model\Redirect\RegexPrefilter;
use Panth\Redirects\Test\Unit\DbStubTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MatcherTest extends TestCase
{
    use DbStubTrait;

    private array $warnings = [];
    private array $cacheSaves = [];
    private int $fetchAllCalls = 0;
    private ?string $cached = null;

    protected function setUp(): void
    {
        $this->warnings = [];
        $this->cacheSaves = [];
        $this->fetchAllCalls = 0;
        $this->cached = null;
    }

    private function row(array $overrides): array
    {
        return array_merge([
            'redirect_id' => 1,
            'store_id'    => 0,
            'match_type'  => 'literal',
            'pattern'     => '/old',
            'target'      => '/new',
            'status_code' => 301,
            'priority'    => 10,
            'is_active'   => 1,
            'start_at'    => null,
            'finish_at'   => null,
        ], $overrides);
    }

    private function matcher(
        array $rows,
        int $maxEvaluations = 0,
        int $backtrackLimit = 0,
        bool $log404 = true,
        ?NotFoundLogger $notFoundLogger = null,
        array $connectionConfig = []
    ): Matcher {
        $connection = $this->connectionStub($connectionConfig + [
            'fetchAll' => function () use ($rows) {
                $this->fetchAllCalls++;
                return $rows;
            },
        ]);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn() => $this->cached ?? false);
        $cache->method('save')->willReturnCallback(
            function ($data, $id, $tags = [], $lifetime = null) {
                $this->cacheSaves[] = ['id' => $id, 'tags' => $tags, 'lifetime' => $lifetime, 'data' => $data];
                return true;
            }
        );

        $factory = $this->createStub(RedirectModelFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn() => (new \ReflectionClass(RedirectModel::class))->newInstanceWithoutConstructor()
        );

        $config = $this->createStub(Config::class);
        $config->method('getRegexMaxEvaluations')->willReturn($maxEvaluations);
        $config->method('getRegexBacktrackLimit')->willReturn($backtrackLimit);
        $config->method('isLog404Enabled')->willReturn($log404);

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            function ($message) {
                $this->warnings[] = (string) $message;
            }
        );

        return new Matcher(
            $this->resourceStub($connection),
            $cache,
            new Json(),
            $factory,
            $notFoundLogger ?? $this->createStub(NotFoundLogger::class),
            $config,
            $logger,
            new RegexPrefilter()
        );
    }

    public function testLiteralRuleMatchesNormalisedPath(): void
    {
        $rule = $this->matcher([$this->row([])])->match('old/?utm=1', 1);

        $this->assertNotNull($rule);
        $this->assertSame('/new', $rule->getTarget());
        $this->assertSame(1, $rule->getRedirectId());
    }

    public function testUnknownPathReturnsNull(): void
    {
        $this->assertNull($this->matcher([$this->row([])])->match('/other', 1));
    }

    public function testStoreScopeIncludesTheDefaultStore(): void
    {
        $this->matcher([])->match('/x', 5);
        $this->assertSame([0, 5], $this->whereValue('store_id IN (?)'));
        $this->assertSame(1, $this->whereValue('is_active = ?'));
    }

    public function testFirstLiteralRowWinsForDuplicatePatterns(): void
    {
        $rule = $this->matcher([
            $this->row(['redirect_id' => 1, 'pattern' => '/dup', 'target' => '/first']),
            $this->row(['redirect_id' => 2, 'pattern' => '/dup/', 'target' => '/second']),
        ])->match('/dup', 1);

        $this->assertSame('/first', $rule->getTarget());
    }

    public function testExpiredRuleIsIgnored(): void
    {
        $matcher = $this->matcher([$this->row(['finish_at' => '2000-01-01 00:00:00'])]);
        $this->assertNull($matcher->match('/old', 1));
    }

    public function testRuleNotYetStartedIsIgnored(): void
    {
        $matcher = $this->matcher([$this->row(['start_at' => '2999-01-01 00:00:00'])]);
        $this->assertNull($matcher->match('/old', 1));
    }

    public function testRuleInsideItsWindowMatches(): void
    {
        $matcher = $this->matcher([$this->row([
            'start_at'  => '2000-01-01 00:00:00',
            'finish_at' => '2999-01-01 00:00:00',
        ])]);
        $this->assertNotNull($matcher->match('/old', 1));
    }

    public function testUnparseableDatesDoNotBlockTheRule(): void
    {
        $matcher = $this->matcher([$this->row(['start_at' => 'not a date', 'finish_at' => 'also bad'])]);
        $this->assertNotNull($matcher->match('/old', 1));
    }

    public function testDangerousLiteralTargetIsBlockedAndLogged(): void
    {
        $matcher = $this->matcher([$this->row(['target' => 'JavaScript:alert(1)'])]);

        $this->assertNull($matcher->match('/old', 1));
        $this->assertContains('[PanthRedirects] Blocked unsafe redirect target', $this->warnings);
    }

    public function testRegexRuleExpandsBackReferences(): void
    {
        $rule = $this->matcher([$this->row([
            'match_type' => 'regex',
            'pattern'    => '^/archive/([0-9]+)$',
            'target'     => '/product/$1',
        ])])->match('/archive/42', 1);

        $this->assertSame('/product/42', $rule->getTarget());
    }

    public function testDelimitedRegexWithFlagsIsUsedAsIs(): void
    {
        $rule = $this->matcher([$this->row([
            'match_type' => 'regex',
            'pattern'    => '#^/SHOP/(.+)$#i',
            'target'     => '/store/$1',
        ])])->match('/shop/shoes', 1);

        $this->assertSame('/store/shoes', $rule->getTarget());
    }

    public function testMaintenanceRuleKeepsItsMessageVerbatim(): void
    {
        $rule = $this->matcher([$this->row([
            'match_type' => 'maintenance',
            'pattern'    => '/checkout',
            'target'     => 'Back soon, costs $1 less',
        ])])->match('/checkout/cart', 1);

        $this->assertSame('Back soon, costs $1 less', $rule->getTarget());
    }

    public function testRegexThatDoesNotMatchFallsThrough(): void
    {
        $matcher = $this->matcher([$this->row(['match_type' => 'regex', 'pattern' => '^/blog/(.+)$'])]);
        $this->assertNull($matcher->match('/news/x', 1));
    }

    public function testInvalidRegexIsSkippedWithAWarning(): void
    {
        $matcher = $this->matcher([
            $this->row(['redirect_id' => 1, 'match_type' => 'regex', 'pattern' => '^/a(']),
            $this->row(['redirect_id' => 2, 'match_type' => 'regex', 'pattern' => '^/a.*$', 'target' => '/ok']),
        ]);

        $this->assertSame('/ok', $matcher->match('/abc', 1)->getTarget());
        $this->assertContains('[PanthRedirects] skipping unparseable regex', $this->warnings);
    }

    public function testExpiredRegexRuleIsSkipped(): void
    {
        $matcher = $this->matcher([$this->row([
            'match_type' => 'regex',
            'pattern'    => '^/a',
            'finish_at'  => '2000-01-01 00:00:00',
        ])]);
        $this->assertNull($matcher->match('/a', 1));
    }

    public function testRegexEvaluationLimitStopsTheScan(): void
    {
        $matcher = $this->matcher([
            $this->row(['redirect_id' => 1, 'match_type' => 'regex', 'pattern' => '^/x/[0-9]+/a$']),
            $this->row(['redirect_id' => 2, 'match_type' => 'regex', 'pattern' => '^/x/.*$', 'target' => '/hit']),
        ], 1);

        $this->assertNull($matcher->match('/x/2', 1));
        $this->assertContains('[PanthRedirects] regex evaluation limit reached', $this->warnings);
    }

    public function testWithoutALimitEveryRegexIsTried(): void
    {
        $matcher = $this->matcher([
            $this->row(['redirect_id' => 1, 'match_type' => 'regex', 'pattern' => '^/x/[0-9]+/a$']),
            $this->row(['redirect_id' => 2, 'match_type' => 'regex', 'pattern' => '^/x/.*$', 'target' => '/hit']),
        ]);

        $this->assertSame('/hit', $matcher->match('/x/2', 1)->getTarget());
    }

    public function testBacktrackLimitIsRestoredAfterMatching(): void
    {
        $before = ini_get('pcre.backtrack_limit');
        $matcher = $this->matcher([$this->row(['match_type' => 'regex', 'pattern' => '^/a'])], 0, 12345);

        $matcher->match('/a', 1);

        $this->assertSame($before, ini_get('pcre.backtrack_limit'));
    }

    public function testTableIsLoadedOncePerStoreAndCached(): void
    {
        $matcher = $this->matcher([$this->row([])]);
        $matcher->match('/old', 1);
        $matcher->match('/other', 1);

        $this->assertSame(1, $this->fetchAllCalls);
        $this->assertCount(1, $this->cacheSaves);
        $this->assertSame('panth_redirects_table_v2_1', $this->cacheSaves[0]['id']);
        $this->assertSame([Matcher::CACHE_TAG], $this->cacheSaves[0]['tags']);
        $this->assertSame(3600, $this->cacheSaves[0]['lifetime']);
    }

    public function testCachedTableAvoidsTheDatabase(): void
    {
        $this->cached = json_encode([
            'literal' => ['/cached' => $this->row(['pattern' => '/cached', 'target' => '/from-cache'])],
            'regex'   => [],
        ]);

        $rule = $this->matcher([$this->row([])])->match('/cached', 1);

        $this->assertSame('/from-cache', $rule->getTarget());
        $this->assertSame(0, $this->fetchAllCalls);
    }

    public function testCorruptCacheFallsBackToTheDatabase(): void
    {
        $this->cached = '{"literal":1}';

        $rule = $this->matcher([$this->row([])])->match('/old', 1);

        $this->assertSame('/new', $rule->getTarget());
        $this->assertSame(1, $this->fetchAllCalls);
    }

    public function testRecordHitIncrementsTheCounter(): void
    {
        $this->matcher([])->recordHit(7);

        $this->assertCount(1, $this->updates);
        $this->assertSame('pfx_panth_seo_redirect', $this->updates[0]['table']);
        $this->assertSame(['redirect_id = ?' => 7], $this->updates[0]['where']);
        $this->assertSame('hit_count + 1', (string) $this->updates[0]['data']['hit_count']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $this->updates[0]['data']['last_hit_at']
        );
    }

    public function testRecordHitSwallowsDatabaseErrors(): void
    {
        $matcher = $this->matcher([], 0, 0, true, null, ['updateThrows' => new \RuntimeException('gone')]);
        $matcher->recordHit(7);

        $this->assertContains('[PanthRedirects] recordHit failed: gone', $this->warnings);
    }

    public function testLog404DelegatesOnlyWhenEnabled(): void
    {
        $logger = $this->createMock(NotFoundLogger::class);
        $logger->expects($this->once())->method('log')->with('/missing', 3, 'https://ref.test', 'UA');
        $this->matcher([], 0, 0, true, $logger)->log404('/missing', 3, 'https://ref.test', 'UA');

        $disabled = $this->createMock(NotFoundLogger::class);
        $disabled->expects($this->never())->method('log');
        $this->matcher([], 0, 0, false, $disabled)->log404('/missing', 3);
    }
}
