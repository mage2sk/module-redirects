<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Service;

use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\State;
use Panth\Redirects\Service\RedirectGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RedirectGuardTest extends TestCase
{
    private const SERVER_KEYS = ['HTTP_X_REQUESTED_WITH', 'CONTENT_TYPE', 'HTTP_SEC_FETCH_MODE'];

    private array $savedServer = [];

    protected function setUp(): void
    {
        foreach (self::SERVER_KEYS as $key) {
            $this->savedServer[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedServer as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }

    private function guard(string $area = 'frontend', bool $areaThrows = false, string $adminFront = 'backend'): RedirectGuard
    {
        $state = $this->createStub(State::class);
        if ($areaThrows) {
            $state->method('getAreaCode')->willThrowException(new \RuntimeException('area not set'));
        } else {
            $state->method('getAreaCode')->willReturn($area);
        }
        $backend = $this->createStub(BackendHelper::class);
        $backend->method('getAreaFrontName')->willReturn($adminFront);

        return new RedirectGuard($state, $backend);
    }

    private function request(
        string $uri = '/some-page',
        string $method = 'GET',
        array $server = [],
        array $headers = [],
        bool $ajax = false
    ): Http {
        $request = $this->createStub(Http::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getRequestUri')->willReturn($uri);
        $request->method('getServer')->willReturnCallback(
            static fn($key = null, $default = null) => $server[$key] ?? $default
        );
        $request->method('getHeader')->willReturnCallback(
            static fn($name, $default = false) => $headers[$name] ?? $default
        );
        $request->method('isAjax')->willReturn($ajax);
        return $request;
    }

    public function testPlainStorefrontGetIsSafe(): void
    {
        $this->assertTrue($this->guard()->isSafeToRedirect($this->request()));
    }

    public function testNonGetMethodsAreNeverRedirected(): void
    {
        $this->assertFalse($this->guard()->isSafeToRedirect($this->request('/x', 'POST')));
        $this->assertFalse($this->guard()->isSafeToRedirect($this->request('/x', 'head')));
        $this->assertTrue($this->guard()->isSafeToRedirect($this->request('/x', 'get')));
    }

    public function testXhrHeaderFromServerBlocks(): void
    {
        $request = $this->request('/x', 'GET', ['HTTP_X_REQUESTED_WITH' => 'xmlhttprequest']);
        $this->assertFalse($this->guard()->isSafeToRedirect($request));
    }

    public function testXhrHeaderFromGlobalServerBlocks(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $this->assertFalse($this->guard()->isSafeToRedirect($this->request()));
    }

    public function testJsonContentTypeFromHeaderBlocks(): void
    {
        $request = $this->request('/x', 'GET', [], ['Content-Type' => 'application/json; charset=utf-8']);
        $this->assertFalse($this->guard()->isSafeToRedirect($request));
    }

    public function testFetchModeOtherThanNavigateBlocks(): void
    {
        $this->assertFalse($this->guard()->isSafeToRedirect(
            $this->request('/x', 'GET', ['HTTP_SEC_FETCH_MODE' => 'cors'])
        ));
        $this->assertTrue($this->guard()->isSafeToRedirect(
            $this->request('/x', 'GET', ['HTTP_SEC_FETCH_MODE' => 'Navigate'])
        ));
    }

    public function testFrameworkAjaxDetectionBlocks(): void
    {
        $this->assertFalse($this->guard()->isSafeToRedirect($this->request('/x', 'GET', [], [], true)));
    }

    public function testConfiguredAdminFrontNameBlocks(): void
    {
        $this->assertFalse($this->guard()->isSafeToRedirect($this->request('/backend/dashboard')));
        $this->assertFalse($this->guard()->isSafeToRedirect($this->request('/BACKEND')));
    }

    public function testDefaultAdminPathAlwaysBlocks(): void
    {
        $this->assertFalse($this->guard('frontend', false, 'custompanel')->isSafeToRedirect($this->request('/admin/x')));
    }

    public function testStorefrontPathsThatOnlyStartWithTheAdminNameAreSafe(): void
    {
        $guard = $this->guard();
        $this->assertTrue($guard->isSafeToRedirect($this->request('/administration-services')));
        $this->assertTrue($guard->isSafeToRedirect($this->request('/backend-developer-jobs')));
        $this->assertFalse($guard->isSafeToRedirect($this->request('/admin?x=1')));
        $this->assertFalse($guard->isSafeToRedirect($this->request('/backend?x=1')));
    }

    public function testNonFrontendAreaBlocks(): void
    {
        $this->assertFalse($this->guard('adminhtml')->isSafeToRedirect($this->request()));
        $this->assertFalse($this->guard('webapi_rest')->isSafeToRedirect($this->request()));
    }

    public function testUnsetAreaDoesNotBlock(): void
    {
        $this->assertTrue($this->guard('frontend', true)->isSafeToRedirect($this->request()));
    }

    public static function skippedPrefixProvider(): array
    {
        return [
            ['/rest/V1/products'],
            ['/soap/default'],
            ['/graphql'],
            ['/static/version1/frontend/x.js'],
            ['/media/catalog/product/a.jpg'],
            ['/pub/media/x'],
            ['/errors/report.php'],
            ['/health_check.php'],
            ['/sitemap.xml'],
            ['/robots.txt'],
            ['/favicon.ico'],
            ['/REST/v1/x'],
        ];
    }

    #[DataProvider('skippedPrefixProvider')]
    public function testInfrastructurePathsAreSkipped(string $uri): void
    {
        $this->assertFalse($this->guard()->isSafeToRedirect($this->request($uri)));
    }
}
