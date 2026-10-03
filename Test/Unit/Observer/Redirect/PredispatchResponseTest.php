<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Observer\Redirect;

use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers what the observer writes to the response for each kind of rule.
 */
class PredispatchResponseTest extends TestCase
{
    private ?array $redirect = null;
    private array $status = [];
    private array $headers = [];
    private ?string $body = null;
    private array $flags = [];
    private array $hits = [];
    private array $warnings = [];
    private int $matchCalls = 0;

    protected function setUp(): void
    {
        $this->redirect = null;
        $this->status = [];
        $this->headers = [];
        $this->body = null;
        $this->flags = [];
        $this->hits = [];
        $this->warnings = [];
        $this->matchCalls = 0;
    }

    private function rule(string $target, int $status = 301, string $type = 'literal', ?int $id = 5): RedirectRuleInterface
    {
        $rule = $this->createStub(RedirectRuleInterface::class);
        $rule->method('getTarget')->willReturn($target);
        $rule->method('getStatusCode')->willReturn($status);
        $rule->method('getMatchType')->willReturn($type);
        $rule->method('getRedirectId')->willReturn($id);
        return $rule;
    }

    private function observer(
        ?RedirectRuleInterface $rule,
        array $options = []
    ): Predispatch {
        $matcher = $this->createStub(RedirectMatcherInterface::class);
        $matcher->method('match')->willReturnCallback(function () use ($rule, $options) {
            $this->matchCalls++;
            if (!empty($options['matcherThrows'])) {
                throw new \RuntimeException('db down');
            }
            return $rule;
        });
        $matcher->method('recordHit')->willReturnCallback(function ($id) {
            $this->hits[] = $id;
        });

        $current = $this->createStub(Store::class);
        $current->method('getId')->willReturn(1);
        $other = $this->createStub(Store::class);
        $other->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($current);
        $storeManager->method('getStores')->willReturn([$other]);

        $request = $this->createStub(HttpRequest::class);
        $request->method('getPathInfo')->willReturn($options['path'] ?? '/old');
        $request->method('getRequestUri')->willReturn($options['path'] ?? '/old');
        $request->method('getBasePath')->willReturn('');
        $request->method('getFrontName')->willReturn($options['front'] ?? 'catalog');

        $response = $this->createStub(HttpResponse::class);
        $response->method('setRedirect')->willReturnCallback(function ($url, $code = 302) use (&$response) {
            $this->redirect = ['url' => $url, 'code' => $code];
            return $response;
        });
        $response->method('setStatusHeader')->willReturnCallback(
            function ($code, $version = null, $phrase = null) use (&$response) {
                $this->status = [$code, $phrase];
                return $response;
            }
        );
        $response->method('setHeader')->willReturnCallback(function ($name, $value) use (&$response) {
            $this->headers[$name] = $value;
            return $response;
        });
        $response->method('setBody')->willReturnCallback(function ($value) use (&$response) {
            $this->body = $value;
            return $response;
        });

        $actionFlag = $this->createStub(ActionFlag::class);
        $actionFlag->method('set')->willReturnCallback(function ($action, $flag, $value) {
            $this->flags[$flag] = $value;
        });

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($options['enabled'] ?? true);
        $config->method('isMatchOriginalUriEnabled')->willReturn(false);

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($m) {
            $this->warnings[] = (string) $m;
        });

        $guard = $this->createStub(RedirectGuard::class);
        $guard->method('isSafeToRedirect')->willReturn(true);

        return new Predispatch(
            $matcher,
            $storeManager,
            $request,
            $response,
            $actionFlag,
            $config,
            $logger,
            $guard,
            new PathNormalizer()
        );
    }

    public function testMaintenanceRuleServesA503WithTheMessage(): void
    {
        $this->observer($this->rule('Back at noon', 301, RedirectRuleInterface::MATCH_MAINTENANCE))
            ->execute(new Observer());

        $this->assertSame([503, 'Service Unavailable'], $this->status);
        $this->assertSame('3600', $this->headers['Retry-After']);
        $this->assertSame('text/plain; charset=UTF-8', $this->headers['Content-Type']);
        $this->assertSame('Back at noon', $this->body);
        $this->assertTrue($this->flags[ActionInterface::FLAG_NO_DISPATCH]);
        $this->assertSame([5], $this->hits);
        $this->assertNull($this->redirect);
    }

    public function testMaintenanceRuleWithoutIdRecordsNoHit(): void
    {
        $this->observer($this->rule('x', 503, RedirectRuleInterface::MATCH_MAINTENANCE, null))->execute(new Observer());
        $this->assertSame([], $this->hits);
    }

    public static function statusProvider(): array
    {
        return [
            'gone'        => [410, 'Gone', false],
            'legal'       => [451, 'Unavailable For Legal Reasons', false],
            'unavailable' => [503, 'Service Unavailable', true],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testNonRedirectStatusesRenderTheTargetAsBody(int $code, string $phrase, bool $retryAfter): void
    {
        $this->observer($this->rule('This page is gone', $code))->execute(new Observer());

        $this->assertSame([$code, $phrase], $this->status);
        $this->assertSame('This page is gone', $this->body);
        $this->assertSame($retryAfter, isset($this->headers['Retry-After']));
        $this->assertNull($this->redirect);
        $this->assertTrue($this->flags[ActionInterface::FLAG_NO_DISPATCH]);
        $this->assertSame([5], $this->hits);
    }

    public function testRelativeTargetGetsALeadingSlash(): void
    {
        $this->observer($this->rule('new-page.html', 302))->execute(new Observer());
        $this->assertSame(['url' => '/new-page.html', 'code' => 302], $this->redirect);
    }

    public function testMissingStatusDefaultsTo301(): void
    {
        $this->observer($this->rule('/new', 0))->execute(new Observer());
        $this->assertSame(301, $this->redirect['code']);
    }

    public function testAbsoluteTargetOnAStoreHostIsFollowed(): void
    {
        $this->observer($this->rule('https://SHOP.test/new'))->execute(new Observer());
        $this->assertSame('https://SHOP.test/new', $this->redirect['url']);
    }

    public function testAbsoluteTargetOnAForeignHostIsBlocked(): void
    {
        $this->observer($this->rule('https://evil.test/phish'))->execute(new Observer());

        $this->assertNull($this->redirect);
        $this->assertSame([], $this->hits);
        $this->assertSame(['[PanthRedirects] redirect blocked: external host not in store URLs'], $this->warnings);
    }

    public function testDangerousSchemeIsBlocked(): void
    {
        $this->observer($this->rule('VBScript:msgbox'))->execute(new Observer());

        $this->assertNull($this->redirect);
        $this->assertSame(['[PanthRedirects] redirect blocked: dangerous URI scheme in target'], $this->warnings);
    }

    public static function unsafeTargetProvider(): array
    {
        return [
            'protocol relative' => ['//evil.test/x'],
            'backslash'         => ['/\\evil.test'],
            'control character' => ["/a\nLocation: x"],
        ];
    }

    #[DataProvider('unsafeTargetProvider')]
    public function testUnsafeRelativeTargetsAreBlocked(string $target): void
    {
        $this->observer($this->rule($target))->execute(new Observer());

        $this->assertNull($this->redirect);
        $this->assertArrayNotHasKey(ActionInterface::FLAG_NO_DISPATCH, $this->flags);
        $this->assertSame(['[PanthRedirects] redirect blocked: unsafe target'], $this->warnings);
    }

    public function testEmptyTargetDoesNothing(): void
    {
        $this->observer($this->rule(''))->execute(new Observer());
        $this->assertNull($this->redirect);
        $this->assertSame([], $this->flags);
    }

    public function testDisabledModuleNeverMatches(): void
    {
        $this->observer($this->rule('/new'), ['enabled' => false])->execute(new Observer());
        $this->assertSame(0, $this->matchCalls);
    }

    public function testAdminRequestsAreIgnored(): void
    {
        $this->observer($this->rule('/new'), ['front' => 'admin'])->execute(new Observer());
        $this->observer($this->rule('/new'), ['front' => '', 'path' => '/admin/dashboard'])->execute(new Observer());

        $this->assertSame(0, $this->matchCalls);
        $this->assertNull($this->redirect);
    }

    public function testMatcherFailureIsLoggedNotThrown(): void
    {
        $this->observer(null, ['matcherThrows' => true])->execute(new Observer());
        $this->assertSame(['[PanthRedirects] redirect predispatch failed: db down'], $this->warnings);
    }
}
