<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Plugin;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Router\NoRouteHandlerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Controller\Router;
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

    public function testRouterGuardBlocksUnsafeRequestsBeforeUrlRewriteMatching(): void
    {
        $called = false;
        $result = (new RouterXhrGuard($this->guard(false)))->aroundMatch(
            $this->createStub(Router::class),
            function () use (&$called) {
                $called = true;
                return null;
            },
            $this->createStub(HttpRequest::class)
        );

        $this->assertNull($result);
        $this->assertFalse($called);
    }

    public function testRouterGuardDelegatesSafeRequests(): void
    {
        $action = $this->createStub(ActionInterface::class);
        $result = (new RouterXhrGuard($this->guard(true)))->aroundMatch(
            $this->createStub(Router::class),
            static fn() => $action,
            $this->createStub(HttpRequest::class)
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
