<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Observer\Redirect;

use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Redirects\Api\Data\RedirectRuleInterface;
use Panth\Redirects\Api\RedirectMatcherInterface;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Model\Redirect\PathNormalizer;
use Panth\Redirects\Observer\Redirect\Predispatch;
use Panth\Redirects\Service\RedirectGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PredispatchTest extends TestCase
{
    private array $matchCalls = [];
    private ?array $redirect = null;
    private array $recordedHits = [];

    protected function setUp(): void
    {
        $this->matchCalls = [];
        $this->redirect = null;
        $this->recordedHits = [];
    }

    private function rule(string $target, int $status = 301, string $matchType = RedirectRuleInterface::MATCH_LITERAL): RedirectRuleInterface
    {
        $rule = $this->createMock(RedirectRuleInterface::class);
        $rule->method('getTarget')->willReturn($target);
        $rule->method('getStatusCode')->willReturn($status);
        $rule->method('getMatchType')->willReturn($matchType);
        $rule->method('getRedirectId')->willReturn(42);

        return $rule;
    }

    private function observer(
        string $pathInfo,
        string $requestUri,
        array $rulesByPath,
        bool $fallbackEnabled = true,
        bool $guardAllows = true
    ): Predispatch {
        $matcher = $this->createMock(RedirectMatcherInterface::class);
        $matcher->method('match')->willReturnCallback(
            function (string $path) use ($rulesByPath) {
                $this->matchCalls[] = $path;

                return $rulesByPath[$path] ?? null;
            }
        );
        $matcher->method('recordHit')->willReturnCallback(
            function ($id): void {
                $this->recordedHits[] = $id;
            }
        );

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getStores')->willReturn([]);

        $request = $this->createMock(HttpRequest::class);
        $request->method('getPathInfo')->willReturn($pathInfo);
        $request->method('getRequestUri')->willReturn($requestUri);
        $request->method('getBasePath')->willReturn('');
        $request->method('getFrontName')->willReturn('blog');

        $response = $this->createMock(HttpResponse::class);
        $response->method('setRedirect')->willReturnCallback(
            function ($url, $code) use ($response) {
                $this->redirect = ['url' => $url, 'code' => $code];

                return $response;
            }
        );

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isMatchOriginalUriEnabled')->willReturn($fallbackEnabled);

        $guard = $this->createMock(RedirectGuard::class);
        $guard->method('isSafeToRedirect')->willReturn($guardAllows);

        return new Predispatch(
            $matcher,
            $storeManager,
            $request,
            $response,
            $this->createMock(ActionFlag::class),
            $config,
            $this->createMock(LoggerInterface::class),
            $guard,
            new PathNormalizer()
        );
    }

    public function testAShadowedUrlRedirectsFromTheOriginalRequestUri(): void
    {
        $observer = $this->observer(
            '/faq/index/view/id/7',
            '/faq/item/old-question',
            ['/faq/item/old-question' => $this->rule('/the-consolidated-page')]
        );

        $observer->execute(new Observer());

        $this->assertSame(['url' => '/the-consolidated-page', 'code' => 301], $this->redirect);
        $this->assertSame(['/faq/index/view/id/7', '/faq/item/old-question'], $this->matchCalls);
        $this->assertSame([42], $this->recordedHits, 'a fallback match must still count a hit');
    }

    public function testAPrimaryMatchWinsAndTheFallbackNeverRuns(): void
    {
        $observer = $this->observer(
            '/routed-path',
            '/original-path',
            [
                '/routed-path' => $this->rule('/primary-target'),
                '/original-path' => $this->rule('/fallback-target'),
            ]
        );

        $observer->execute(new Observer());

        $this->assertSame('/primary-target', $this->redirect['url']);
        $this->assertSame(['/routed-path'], $this->matchCalls, 'the fallback must not run after a primary hit');
    }

    public function testTheFallbackIsSkippedWhenBothPathsAreTheSame(): void
    {
        $observer = $this->observer('/same-path', '/same-path', []);

        $observer->execute(new Observer());

        $this->assertNull($this->redirect);
        $this->assertSame(['/same-path'], $this->matchCalls, 'no second lookup for an identical path');
    }

    public function testTrailingSlashAndQueryAreNormalizedOnTheFallbackPath(): void
    {
        $observer = $this->observer(
            '/faq/index/index',
            '/blog/old-slug/?ref=news',
            ['/blog/old-slug' => $this->rule('/blog/new-slug')]
        );

        $observer->execute(new Observer());

        $this->assertSame('/blog/new-slug', $this->redirect['url']);
    }

    public function testASelfReferentialRuleDoesNotRedirect(): void
    {
        $observer = $this->observer(
            '/cms/noroute/index',
            '/shadowed-loop',
            ['/shadowed-loop' => $this->rule('/shadowed-loop')]
        );

        $observer->execute(new Observer());

        $this->assertNull($this->redirect, 'a rule whose target equals its source must not redirect to itself');
    }

    public function testASelfReferentialRuleWithATrailingSlashAlsoDoesNotRedirect(): void
    {
        $observer = $this->observer(
            '/cms/noroute/index',
            '/shadowed-loop/',
            ['/shadowed-loop' => $this->rule('/shadowed-loop/')]
        );

        $observer->execute(new Observer());

        $this->assertNull($this->redirect);
    }

    public function testTheFallbackCanBeTurnedOff(): void
    {
        $observer = $this->observer(
            '/faq/index/index',
            '/faq',
            ['/faq' => $this->rule('/somewhere')],
            false
        );

        $observer->execute(new Observer());

        $this->assertNull($this->redirect);
        $this->assertSame(['/faq/index/index'], $this->matchCalls);
    }

    public function testAGuardedRequestSkipsBothLookups(): void
    {
        $observer = $this->observer(
            '/faq/index/index',
            '/faq',
            ['/faq' => $this->rule('/somewhere')],
            true,
            false
        );

        $observer->execute(new Observer());

        $this->assertNull($this->redirect);
        $this->assertSame([], $this->matchCalls);
    }

    public function testAShadowedUrlWithNoRuleFallsThroughToANormalFourOhFour(): void
    {
        $observer = $this->observer('/cms/noroute/index', '/blog/does-not-exist', []);

        $observer->execute(new Observer());

        $this->assertNull($this->redirect);
        $this->assertSame(['/cms/noroute/index', '/blog/does-not-exist'], $this->matchCalls);
    }

    public function testCaseIsNotAlteredByTheFallback(): void
    {
        $observer = $this->observer(
            '/cms/noroute/index',
            '/blog/OLD-SLUG',
            ['/blog/old-slug' => $this->rule('/blog/new-slug')]
        );

        $observer->execute(new Observer());

        $this->assertNull($this->redirect, 'literal matching stays case sensitive');
        $this->assertContains('/blog/OLD-SLUG', $this->matchCalls);
    }
}
