<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Plugin\Redirect;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Plugin\Redirect\HomepageRedirectPlugin;
use Panth\Redirects\Plugin\Redirect\LowercaseRedirectPlugin;
use Panth\Redirects\Plugin\Redirect\TrailingSlashRedirectPlugin;
use Panth\Redirects\Service\RedirectGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FrontControllerPluginsTest extends TestCase
{
    private ?array $redirect = null;
    private array $headers = [];
    private bool $proceeded = false;
    private array $warnings = [];
    private array $guardMethods = [];
    private ResponseInterface $proceedResult;

    protected function setUp(): void
    {
        $this->redirect = null;
        $this->headers = [];
        $this->proceeded = false;
        $this->warnings = [];
        $this->guardMethods = [];
        $this->proceedResult = $this->createStub(ResponseInterface::class);
    }

    private function response(): HttpResponse
    {
        $response = $this->createStub(HttpResponse::class);
        $response->method('setRedirect')->willReturnCallback(
            function ($url, $code = 302) use (&$response) {
                $this->redirect = ['url' => $url, 'code' => $code];
                return $response;
            }
        );
        $response->method('setHeader')->willReturnCallback(
            function ($name, $value, $replace = false) use (&$response) {
                $this->headers[$name] = $value;
                return $response;
            }
        );
        return $response;
    }

    private function config(array $flags): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($flags['enabled'] ?? true);
        $config->method('isLowercaseRedirectEnabled')->willReturn($flags['lowercase'] ?? true);
        $config->method('canonicalRemoveTrailingSlash')->willReturn($flags['slash'] ?? true);
        $config->method('isHomepageRedirectEnabled')->willReturn($flags['homepage'] ?? true);
        return $config;
    }

    private function guard(bool $safe = true): RedirectGuard
    {
        $guard = $this->createStub(RedirectGuard::class);
        $guard->method('isSafeToRedirect')->willReturnCallback(function ($request) use ($safe) {
            $this->guardMethods[] = $request->getMethod();
            return $safe;
        });
        return $guard;
    }

    private function request(string $uri, string $method = 'GET', string $pathInfo = ''): HttpRequest
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getRequestUri')->willReturn($uri);
        $request->method('getPathInfo')->willReturn($pathInfo);
        $current = $method;
        $request->method('getMethod')->willReturnCallback(static function () use (&$current) {
            return $current;
        });
        $request->method('setMethod')->willReturnCallback(static function ($m) use (&$current, &$request) {
            $current = $m;
            return $request;
        });
        return $request;
    }

    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($m) {
            $this->warnings[] = (string) $m;
        });
        return $logger;
    }

    private function proceed(): callable
    {
        return function () {
            $this->proceeded = true;
            return $this->proceedResult;
        };
    }

    private function lowercase(array $flags = [], bool $safe = true): LowercaseRedirectPlugin
    {
        return new LowercaseRedirectPlugin($this->config($flags), $this->response(), $this->logger(), $this->guard($safe));
    }

    private function trailing(array $flags = [], bool $safe = true): TrailingSlashRedirectPlugin
    {
        return new TrailingSlashRedirectPlugin($this->config($flags), $this->response(), $this->logger(), $this->guard($safe));
    }

    private function homepage(array $flags = [], bool $safe = true, bool $storeThrows = false): HomepageRedirectPlugin
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($storeThrows) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));
        } else {
            $storeManager->method('getStore')->willReturn($store);
        }
        return new HomepageRedirectPlugin(
            $this->config($flags),
            $this->response(),
            $storeManager,
            $this->logger(),
            $this->guard($safe)
        );
    }

    private function subject(): FrontControllerInterface
    {
        return $this->createStub(FrontControllerInterface::class);
    }

    public function testLowercaseRedirectKeepsTheQueryUntouched(): void
    {
        $result = $this->lowercase()->aroundDispatch(
            $this->subject(),
            $this->proceed(),
            $this->request('/Women/Tops.html?Color=Red')
        );

        $this->assertInstanceOf(HttpResponse::class, $result);
        $this->assertSame(['url' => '/women/tops.html?Color=Red', 'code' => 301], $this->redirect);
        $this->assertFalse($this->proceeded);
    }

    public function testLowercaseIgnoresAlreadyLowercasePaths(): void
    {
        $result = $this->lowercase()->aroundDispatch($this->subject(), $this->proceed(), $this->request('/women?Q=A'));

        $this->assertSame($this->proceedResult, $result);
        $this->assertNull($this->redirect);
    }

    public function testLowercaseCollapsesLeadingSlashesToAvoidOpenRedirects(): void
    {
        $this->lowercase()->aroundDispatch($this->subject(), $this->proceed(), $this->request('/\\Evil.Test/X'));
        $this->assertSame('/evil.test/x', $this->redirect['url']);
    }

    public static function lowercaseSkipProvider(): array
    {
        return [
            'feature off'    => [['lowercase' => false], true, '/Abc'],
            'module off'     => [['enabled' => false], true, '/Abc'],
            'guard says no'  => [[], false, '/Abc'],
            'root uri'       => [[], true, '/'],
            'empty uri'      => [[], true, ''],
        ];
    }

    #[DataProvider('lowercaseSkipProvider')]
    public function testLowercaseSkipsWhenNotApplicable(array $flags, bool $safe, string $uri): void
    {
        $this->lowercase($flags, $safe)->aroundDispatch($this->subject(), $this->proceed(), $this->request($uri));
        $this->assertTrue($this->proceeded);
        $this->assertNull($this->redirect);
    }

    public function testTrailingSlashIsStrippedAndQueryKept(): void
    {
        $this->trailing()->aroundDispatch($this->subject(), $this->proceed(), $this->request('/women/tops///?p=2'));

        $this->assertSame(['url' => '/women/tops?p=2', 'code' => 301], $this->redirect);
        $this->assertFalse($this->proceeded);
    }

    public static function trailingSkipProvider(): array
    {
        return [
            'no trailing slash' => [[], true, '/women'],
            'feature off'       => [['slash' => false], true, '/women/'],
            'module off'        => [['enabled' => false], true, '/women/'],
            'guard says no'     => [[], false, '/women/'],
            'root uri'          => [[], true, '/'],
            'query only slash'  => [[], true, '/women?x=/'],
        ];
    }

    #[DataProvider('trailingSkipProvider')]
    public function testTrailingSlashSkipsWhenNotApplicable(array $flags, bool $safe, string $uri): void
    {
        $this->trailing($flags, $safe)->aroundDispatch($this->subject(), $this->proceed(), $this->request($uri));
        $this->assertTrue($this->proceeded);
        $this->assertNull($this->redirect);
    }

    public static function homepageAliasProvider(): array
    {
        return [['/index.php'], ['/home'], ['/HOME/'], ['/cms/index'], ['/cms/index/index']];
    }

    #[DataProvider('homepageAliasProvider')]
    public function testHomepageAliasesRedirectToTheBaseUrl(string $path): void
    {
        $this->homepage()->aroundDispatch($this->subject(), $this->proceed(), $this->request($path, 'GET', $path));

        $this->assertSame(['url' => 'https://shop.test/', 'code' => 301], $this->redirect);
        $this->assertSame('', $this->headers['X-Magento-Tags']);
        $this->assertFalse($this->proceeded);
    }

    public function testHomepageUsesRequestUriWhenPathInfoIsRoot(): void
    {
        $this->homepage()->aroundDispatch($this->subject(), $this->proceed(), $this->request('/home?x=1', 'GET', '/'));
        $this->assertSame('https://shop.test/', $this->redirect['url']);
    }

    public function testHeadRequestIsCheckedAsGetAndMethodIsRestored(): void
    {
        $request = $this->request('/home', 'HEAD', '/home');

        $this->homepage()->aroundDispatch($this->subject(), $this->proceed(), $request);

        $this->assertSame(['GET'], $this->guardMethods);
        $this->assertSame('HEAD', $request->getMethod());
        $this->assertNotNull($this->redirect);
    }

    public static function homepageSkipProvider(): array
    {
        return [
            'not an alias'  => [[], true, 'GET', '/homepage'],
            'post request'  => [[], true, 'POST', '/home'],
            'feature off'   => [['homepage' => false], true, 'GET', '/home'],
            'module off'    => [['enabled' => false], true, 'GET', '/home'],
            'guard says no' => [[], false, 'GET', '/home'],
        ];
    }

    #[DataProvider('homepageSkipProvider')]
    public function testHomepageSkipsWhenNotApplicable(array $flags, bool $safe, string $method, string $path): void
    {
        $this->homepage($flags, $safe)->aroundDispatch(
            $this->subject(),
            $this->proceed(),
            $this->request($path, $method, $path)
        );
        $this->assertTrue($this->proceeded);
        $this->assertNull($this->redirect);
    }

    public function testHomepageFailureFallsBackToNormalDispatch(): void
    {
        $result = $this->homepage([], true, true)->aroundDispatch(
            $this->subject(),
            $this->proceed(),
            $this->request('/home', 'GET', '/home')
        );

        $this->assertSame($this->proceedResult, $result);
        $this->assertSame(['[PanthRedirects] Homepage redirect plugin failed, proceeding normally'], $this->warnings);
    }
}
