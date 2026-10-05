<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Observer\Redirect;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Model\Redirect\AutoRedirectService;
use Panth\Redirects\Observer\Redirect\CategoryDeleteBefore;
use Panth\Redirects\Observer\Redirect\CmsPageDeleteBefore;
use Panth\Redirects\Observer\Redirect\ProductDeleteBefore;
use Panth\Redirects\Test\Unit\DbStubTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeleteObserversTest extends TestCase
{
    use DbStubTrait;

    private array $created = [];
    private array $errors = [];

    protected function setUp(): void
    {
        $this->created = [];
        $this->errors = [];
    }

    private function autoRedirect(): AutoRedirectService
    {
        $service = $this->createStub(AutoRedirectService::class);
        $service->method('createRedirect')->willReturnCallback(function ($source, $target, $storeId) {
            $this->created[] = [$source, $target, $storeId];
        });
        return $service;
    }

    private function config(bool $enabled = true, bool $auto = true, string $strategy = 'parent_category', string $custom = '', array $disabledStores = []): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(
            static fn($storeId = null) => $enabled && !in_array($storeId, $disabledStores, true)
        );
        $config->method('isAutoRedirectEnabled')->willReturn($auto);
        $config->method('getAutoRedirectTargetStrategy')->willReturn($strategy);
        $config->method('getAutoRedirectCustomUrl')->willReturn($custom);
        return $config;
    }

    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($m) {
            $this->errors[] = (string) $m;
        });
        return $logger;
    }

    private function observerFor(array $eventData): Observer
    {
        return new Observer(['event' => new Event($eventData)]);
    }

    private function page(array $data): Page
    {
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->setData($data);
        return $page;
    }

    public function testDeletedCmsPageRedirectsToHomeInEveryStore(): void
    {
        $observer = new CmsPageDeleteBefore($this->autoRedirect(), $this->config(), $this->logger());
        $observer->execute($this->observerFor([
            'object' => $this->page(['page_id' => 4, 'identifier' => 'about-us', 'store_id' => [1, 2]]),
        ]));

        $this->assertSame([['about-us', '/', 1], ['about-us', '/', 2]], $this->created);
    }

    public function testScalarStoreIdOnCmsPageIsAccepted(): void
    {
        $observer = new CmsPageDeleteBefore($this->autoRedirect(), $this->config(), $this->logger());
        $observer->execute($this->observerFor([
            'page' => $this->page(['page_id' => 4, 'identifier' => 'faq', 'store_id' => '0']),
        ]));

        $this->assertSame([['faq', '/', 0]], $this->created);
    }

    public static function protectedPageProvider(): array
    {
        return [['home'], ['no-route'], ['']];
    }

    #[DataProvider('protectedPageProvider')]
    public function testSystemCmsPagesAreNeverRedirected(string $identifier): void
    {
        $observer = new CmsPageDeleteBefore($this->autoRedirect(), $this->config(), $this->logger());
        $observer->execute($this->observerFor([
            'object' => $this->page(['page_id' => 4, 'identifier' => $identifier, 'store_id' => [1]]),
        ]));

        $this->assertSame([], $this->created);
    }

    public function testCmsObserverSkipsUnsavedPagesAndDisabledFeature(): void
    {
        (new CmsPageDeleteBefore($this->autoRedirect(), $this->config(), $this->logger()))
            ->execute($this->observerFor(['object' => $this->page(['identifier' => 'x', 'store_id' => [1]])]));
        (new CmsPageDeleteBefore($this->autoRedirect(), $this->config(true, false), $this->logger()))
            ->execute($this->observerFor(['object' => $this->page(['page_id' => 1, 'identifier' => 'x', 'store_id' => [1]])]));
        (new CmsPageDeleteBefore($this->autoRedirect(), $this->config(), $this->logger()))
            ->execute($this->observerFor(['object' => new \stdClass()]));

        $this->assertSame([], $this->created);
    }

    private function category(int $id, int $parentId): Category
    {
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getParentId')->willReturn($parentId);
        return $category;
    }

    private function categoryObserver(array $rewrites, array $fetchOneQueue = [], bool $fetchOneThrows = false): CategoryDeleteBefore
    {
        $connection = $this->connectionStub([
            'fetchAll' => $rewrites,
            'fetchOne' => function () use (&$fetchOneQueue, $fetchOneThrows) {
                if ($fetchOneThrows) {
                    throw new \RuntimeException('db');
                }
                return array_shift($fetchOneQueue) ?? false;
            },
        ]);
        return new CategoryDeleteBefore($this->autoRedirect(), $this->config(), $this->resourceStub($connection), $this->logger());
    }

    public function testDeletedCategoryRedirectsToItsParentCategoryUrl(): void
    {
        $observer = $this->categoryObserver(
            [['request_path' => 'women/tops.html', 'store_id' => '1'], ['request_path' => '', 'store_id' => '1']],
            ['2', 'women.html']
        );
        $observer->execute($this->observerFor(['category' => $this->category(12, 5)]));

        $this->assertSame([['women/tops.html', 'women.html', 1]], $this->created);
        $this->assertSame(12, $this->whereValue('entity_id = ?'));
    }

    public function testParentCategoryUrlIsLookedUpPerStore(): void
    {
        $observer = $this->categoryObserver(
            [['request_path' => 'women/tops.html', 'store_id' => '1'], ['request_path' => 'femmes/hauts.html', 'store_id' => '2']],
            ['2', 'women.html', '2', 'femmes.html']
        );
        $observer->execute($this->observerFor(['category' => $this->category(12, 5)]));

        $this->assertSame(
            [['women/tops.html', 'women.html', 1], ['femmes/hauts.html', 'femmes.html', 2]],
            $this->created
        );
        $this->assertSame(1, $this->whereValue('store_id = ?'));
    }

    public function testTopLevelCategoryRedirectsHome(): void
    {
        $this->categoryObserver([['request_path' => 'women.html', 'store_id' => 1]])
            ->execute($this->observerFor(['object' => $this->category(5, 1)]));
        $this->assertSame([['women.html', '/', 1]], $this->created);
    }

    public function testParentAtRootLevelRedirectsHome(): void
    {
        $this->categoryObserver([['request_path' => 'a.html', 'store_id' => 1]], ['1'])
            ->execute($this->observerFor(['entity' => $this->category(5, 2)]));
        $this->assertSame([['a.html', '/', 1]], $this->created);
    }

    public function testParentWithoutRewriteRedirectsHome(): void
    {
        $this->categoryObserver([['request_path' => 'a.html', 'store_id' => 1]], ['3', false])
            ->execute($this->observerFor(['category' => $this->category(5, 9)]));
        $this->assertSame([['a.html', '/', 1]], $this->created);
    }

    public function testParentLookupFailureRedirectsHome(): void
    {
        $this->categoryObserver([['request_path' => 'a.html', 'store_id' => 1]], [], true)
            ->execute($this->observerFor(['category' => $this->category(5, 9)]));
        $this->assertSame([['a.html', '/', 1]], $this->created);
    }

    public function testCategoryWithoutRewritesCreatesNothing(): void
    {
        $this->categoryObserver([])->execute($this->observerFor(['category' => $this->category(5, 9)]));
        $this->assertSame([], $this->created);
    }

    private function product(int $id, array $categoryIds = []): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getCategoryIds')->willReturn($categoryIds);
        return $product;
    }

    private function productObserver(Config $config, array $rewrites, mixed $categoryPath = false): ProductDeleteBefore
    {
        $connection = $this->connectionStub(['fetchAll' => $rewrites, 'fetchOne' => $categoryPath]);
        return new ProductDeleteBefore(
            $this->autoRedirect(),
            $config,
            $this->resourceStub($connection),
            $this->createStub(StoreManagerInterface::class),
            $this->logger()
        );
    }

    public function testDeletedProductRedirectsToItsFirstCategory(): void
    {
        $observer = $this->productObserver(
            $this->config(),
            [['request_path' => 'shirt.html', 'store_id' => '1']],
            'men/shirts.html'
        );
        $observer->execute($this->observerFor(['product' => $this->product(3, [7, 8])]));

        $this->assertSame([['shirt.html', 'men/shirts.html', 1]], $this->created);
        $this->assertSame(1, $this->whereValue('store_id = ?'));
    }

    public function testProductWithoutCategoriesRedirectsHome(): void
    {
        $this->productObserver($this->config(), [['request_path' => 'shirt.html', 'store_id' => 1]])
            ->execute($this->observerFor(['product' => $this->product(3)]));
        $this->assertSame([['shirt.html', '/', 1]], $this->created);
    }

    public function testHomepageStrategyIgnoresCategories(): void
    {
        $this->productObserver($this->config(true, true, 'homepage'), [['request_path' => 'p.html', 'store_id' => 1]], 'cat.html')
            ->execute($this->observerFor(['object' => $this->product(3, [7])]));
        $this->assertSame([['p.html', '/', 1]], $this->created);
    }

    public static function customUrlProvider(): array
    {
        return [
            'relative gets slash' => ['sale', '/sale'],
            'absolute path kept'  => ['/sale/', '/sale/'],
            'empty falls back'    => ['  ', '/'],
            'scheme rejected'     => ['https://evil.test', '/'],
            'protocol relative'   => ['//evil.test', '/'],
            'traversal rejected'  => ['a/../b', '/'],
            'control char'        => ["sa\x01le", '/'],
        ];
    }

    #[DataProvider('customUrlProvider')]
    public function testCustomUrlStrategyIsSanitised(string $custom, string $expected): void
    {
        $this->productObserver($this->config(true, true, 'custom_url', $custom), [['request_path' => 'p.html', 'store_id' => 1]])
            ->execute($this->observerFor(['entity' => $this->product(3)]));
        $this->assertSame([['p.html', $expected, 1]], $this->created);
    }

    public function testStoresWithTheFeatureDisabledAreSkipped(): void
    {
        $observer = $this->productObserver(
            $this->config(true, true, 'homepage', '', [2]),
            [['request_path' => 'a.html', 'store_id' => 1], ['request_path' => 'b.html', 'store_id' => 2], ['request_path' => '', 'store_id' => 1]]
        );
        $observer->execute($this->observerFor(['product' => $this->product(3)]));

        $this->assertSame([['a.html', '/', 1]], $this->created);
    }

    public function testProductObserverSkipsWhenGloballyDisabledOrUnsaved(): void
    {
        $this->productObserver($this->config(false), [['request_path' => 'a.html', 'store_id' => 1]])
            ->execute($this->observerFor(['product' => $this->product(3)]));
        $this->productObserver($this->config(), [['request_path' => 'a.html', 'store_id' => 1]])
            ->execute($this->observerFor(['product' => $this->product(0)]));

        $this->assertSame([], $this->created);
    }

    public function testFailuresAreLoggedAsErrors(): void
    {
        $service = $this->createStub(AutoRedirectService::class);
        $service->method('createRedirect')->willThrowException(new \RuntimeException('x'));
        $observer = new ProductDeleteBefore(
            $service,
            $this->config(true, true, 'homepage'),
            $this->resourceStub($this->connectionStub(['fetchAll' => [['request_path' => 'a', 'store_id' => 1]]])),
            $this->createStub(StoreManagerInterface::class),
            $this->logger()
        );
        $observer->execute($this->observerFor(['product' => $this->product(3)]));

        $this->assertSame(['[PanthRedirects] ProductDeleteBefore observer failed'], $this->errors);
    }
}
