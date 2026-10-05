<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Plugin;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Router\NoRouteHandlerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Controller\Router;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Panth\Redirects\Api\Data\RedirectRuleInterface;
use Panth\Redirects\Api\RedirectMatcherInterface;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Model\Redirect\NotFoundLogger;
use Panth\Redirects\Plugin\NoRouteLoggerPlugin;
use Panth\Redirects\Plugin\UrlRewrite\RouterXhrGuard;
use Panth\Redirects\Service\RedirectGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RequestPluginsTest extends TestCase
{
    private array $logged = [];
    private array $warnings = [];

    private function guard(bool $safe): RedirectGuard
    {
        $guard = $this->createStub(RedirectGuard::class);
        $guard->method('isSafeToRedirect')->willReturn($safe);
        return $guard;
    }

    private function routerGuard(bool $safe, ?int $redirectType, bool $finderThrows = false): RouterXhrGuard
    {
        $finder = $this->createStub(UrlFinderInterface::class);
        if ($finderThrows) {
            $finder->method('findOneByData')->willThrowException(new \RuntimeException('db'));
        } else {
            $finder->method('findOneByData')->willReturnCallback(
                static function (array $data) use ($redirectType) {
                    if ($redirectType === null) {
                        return null;
                    }
                    return new UrlRewrite([
                        UrlRewrite::REQUEST_PATH => $data[UrlRewrite::REQUEST_PATH],
                        UrlRewrite::REDIRECT_TYPE => $redirectType,
                    ], new Json());
                }
            );
        }
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return new RouterXhrGuard($this->guard($safe), $finder, $storeManager);
    }

    private function pathRequest(string $path): HttpRequest
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getPathInfo')->willReturn($path);
        return $request;
    }

    public function testUnsafeRequestToRedirectRewriteIsNotRedirected(): void
    {
        $called = false;
        $result = $this->routerGuard(false, 301)->aroundMatch(
            $this->createStub(Router::class),
            function () use (&$called) {
                $called = true;
                return null;
            },
            $this->pathRequest('/old-product.html')
        );

        $this->assertNull($result);
        $this->assertFalse($called);
    }

    public function testUnsafeRequestToCatalogRewriteStillResolves(): void
    {
        $action = $this->createStub(ActionInterface::class);
        foreach ([0, null] as $type) {
            $result = $this->routerGuard(false, $type)->aroundMatch(
                $this->createStub(Router::class),
                static fn() => $action,
                $this->pathRequest('/chaz-kangeroo-hoodie.html')
            );
            $this->assertSame($action, $result);
        }
    }

    public function testFinderFailureFallsBackToNormalMatching(): void
    {
        $action = $this->createStub(ActionInterface::class);
        $result = $this->routerGuard(false, 301, true)->aroundMatch(
            $this->createStub(Router::class),
            static fn() => $action,
            $this->pathRequest('/gear/bags.html')
        );
        $this->assertSame($action, $result);
    }

    public function testRouterGuardDelegatesSafeRequests(): void
    {
        $action = $this->createStub(ActionInterface::class);
        $result = $this->routerGuard(true, 301)->aroundMatch(
            $this->createStub(Router::class),
            static fn() => $action,
            $this->pathRequest('/old-product.html')
        );

        $this->assertSame($action, $result);
    }

    private function noRoutePlugin(
        bool $enabled = true,
        bool $log404 = true,
        ?RedirectRuleInterface $rule = null,
        bool $storeThrows = false
    ): NoRouteLoggerPlugin {
        $notFound = $this->createStub(NotFoundLogger::class);
        $notFound->method('log')->willReturnCallback(function (...$args) {
            $this->logged[] = $args;
        });

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($storeThrows) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('boom'));
        } else {
            $storeManager->method('getStore')->willReturn($store);
        }

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('isLog404Enabled')->willReturn($log404);

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($m) {
            $this->warnings[] = (string) $m;
        });

        $matcher = $this->createStub(RedirectMatcherInterface::class);
        $matcher->method('match')->willReturn($rule);

        return new NoRouteLoggerPlugin($notFound, $storeManager, $config, $logger, $matcher);
    }

    private function request(array $server = []): HttpRequest
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getPathInfo')->willReturn('/missing');
        $request->method('getServer')->willReturnCallback(static fn($key) => $server[$key] ?? null);
        return $request;
    }

    public function testUnmatched404IsLoggedWithRefererAndAgent(): void
    {
        $result = $this->noRoutePlugin()->afterProcess(
            $this->createStub(NoRouteHandlerInterface::class),
            true,
            $this->request(['HTTP_REFERER' => 'https://ref.test', 'HTTP_USER_AGENT' => 'Bot'])
        );

        $this->assertTrue($result);
        $this->assertSame([['/missing', 3, 'https://ref.test', 'Bot']], $this->logged);
    }

    public function testMissingHeadersAreLoggedAsNull(): void
    {
        $this->noRoutePlugin()->afterProcess($this->createStub(NoRouteHandlerInterface::class), false, $this->request());
        $this->assertSame([['/missing', 3, null, null]], $this->logged);
    }

    public function testPathsCoveredByARedirectAreNotLogged(): void
    {
        $plugin = $this->noRoutePlugin(true, true, $this->createStub(RedirectRuleInterface::class));
        $plugin->afterProcess($this->createStub(NoRouteHandlerInterface::class), true, $this->request());
        $this->assertSame([], $this->logged);
    }

    public function testNothingIsLoggedWhenDisabled(): void
    {
        $this->noRoutePlugin(false)->afterProcess($this->createStub(NoRouteHandlerInterface::class), true, $this->request());
        $this->noRoutePlugin(true, false)->afterProcess($this->createStub(NoRouteHandlerInterface::class), true, $this->request());
        $this->assertSame([], $this->logged);
    }

    public function testFailuresNeverChangeTheResult(): void
    {
        $result = $this->noRoutePlugin(true, true, null, true)
            ->afterProcess($this->createStub(NoRouteHandlerInterface::class), false, $this->request());

        $this->assertFalse($result);
        $this->assertSame(['[PanthRedirects] 404 logger plugin failed: boom'], $this->warnings);
    }
}
