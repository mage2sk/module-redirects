<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Controller\Adminhtml\Redirect;

use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Magento\Ui\Component\MassAction\Filter;
use Panth\Redirects\Controller\Adminhtml\AbstractAction;
use Panth\Redirects\Controller\Adminhtml\Redirect\Delete;
use Panth\Redirects\Controller\Adminhtml\Redirect\Edit;
use Panth\Redirects\Controller\Adminhtml\Redirect\MassDelete;
use Panth\Redirects\Controller\Adminhtml\Redirect\MassStatus;
use Panth\Redirects\Model\Redirect\Matcher;
use Panth\Redirects\Model\ResourceModel\Redirect\Collection;
use Panth\Redirects\Model\ResourceModel\Redirect\CollectionFactory;
use Panth\Redirects\Test\Unit\Controller\Adminhtml\ControllerTestCase;
use Panth\Redirects\Test\Unit\DbStubTrait;

class GridActionsTest extends ControllerTestCase
{
    use DbStubTrait;

    private array $cleaned = [];

    private function cache(): CacheInterface
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('clean')->willReturnCallback(function ($tags) {
            $this->cleaned[] = $tags;
            return true;
        });
        return $cache;
    }

    private function selection(array $ids): array
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getAllIds')->willReturn($ids);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willReturn($collection);
        return [$filter, $factory];
    }

    private function dateTime(): DateTime
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-03 10:00:00');
        return $dateTime;
    }

    public function testDeleteRemovesTheRuleAndFlushesTheCache(): void
    {
        $action = new Delete($this->context(['id' => '9']), $this->resourceStub($this->connectionStub()), $this->cache());
        $action->execute();

        $this->assertSame([['table' => 'pfx_panth_seo_redirect', 'where' => ['redirect_id = ?' => 9]]], $this->deletes);
        $this->assertSame([[Matcher::CACHE_TAG]], $this->cleaned);
        $this->assertSame(['Redirect deleted.'], $this->messages['success']);
        $this->assertSame('*/*/', $this->redirect['path']);
    }

    public function testDeleteWithoutIdDoesNothing(): void
    {
        (new Delete($this->context(), $this->resourceStub($this->connectionStub()), $this->cache()))->execute();

        $this->assertSame([], $this->deletes);
        $this->assertSame([], $this->messages['success']);
        $this->assertSame('*/*/', $this->redirect['path']);
    }

    public function testDeleteErrorIsShown(): void
    {
        $connection = $this->connectionStub(['deleteThrows' => new \RuntimeException('fk')]);
        (new Delete($this->context(['id' => 1]), $this->resourceStub($connection), $this->cache()))->execute();

        $this->assertSame(['fk'], $this->messages['error']);
        $this->assertSame([], $this->cleaned);
    }

    public function testMassDeleteRemovesSelectedIds(): void
    {
        [$filter, $factory] = $this->selection(['3', '0', '5']);
        $action = new MassDelete(
            $this->context(),
            $filter,
            $factory,
            $this->resourceStub($this->connectionStub(['deleteResult' => 2])),
            $this->cache()
        );
        $action->execute();

        $this->assertSame(['redirect_id IN (?)' => [3, 5]], $this->deletes[0]['where']);
        $this->assertSame(['A total of 2 redirect(s) have been deleted.'], $this->messages['success']);
        $this->assertSame([[Matcher::CACHE_TAG]], $this->cleaned);
    }

    public function testMassDeleteWithEmptySelectionShowsAnError(): void
    {
        [$filter, $factory] = $this->selection([]);
        (new MassDelete($this->context(), $filter, $factory, $this->resourceStub($this->connectionStub()), $this->cache()))
            ->execute();

        $this->assertSame(['Please select at least one redirect.'], $this->messages['error']);
        $this->assertSame([], $this->deletes);
    }

    public function testMassStatusEnablesSelectedIds(): void
    {
        [$filter, $factory] = $this->selection([4, 6]);
        $action = new MassStatus(
            $this->context(['status' => '1']),
            $filter,
            $factory,
            $this->resourceStub($this->connectionStub()),
            $this->cache(),
            $this->dateTime()
        );
        $action->execute();

        $this->assertSame(['is_active' => 1, 'updated_at' => '2026-10-03 10:00:00'], $this->updates[0]['data']);
        $this->assertSame(['redirect_id IN (?)' => [4, 6]], $this->updates[0]['where']);
        $this->assertSame(['A total of 2 redirect(s) have been enabled.'], $this->messages['success']);
    }

    public function testMassStatusDisables(): void
    {
        [$filter, $factory] = $this->selection([4]);
        (new MassStatus($this->context(['status' => '0']), $filter, $factory, $this->resourceStub($this->connectionStub()), $this->cache(), $this->dateTime()))
            ->execute();

        $this->assertSame(0, $this->updates[0]['data']['is_active']);
        $this->assertSame(['A total of 1 redirect(s) have been disabled.'], $this->messages['success']);
    }

    public function testMassStatusRejectsMissingOrInvalidStatus(): void
    {
        foreach ([[], ['status' => '2'], ['status' => 'yes']] as $params) {
            [$filter, $factory] = $this->selection([4]);
            (new MassStatus($this->context($params), $filter, $factory, $this->resourceStub($this->connectionStub()), $this->cache(), $this->dateTime()))
                ->execute();
            $this->assertSame(['Missing status parameter.'], $this->messages['error']);
        }
        $this->assertSame([], $this->updates);
    }

    public function testMassStatusWithEmptySelectionShowsAnError(): void
    {
        [$filter, $factory] = $this->selection([0]);
        (new MassStatus($this->context(['status' => '1']), $filter, $factory, $this->resourceStub($this->connectionStub()), $this->cache(), $this->dateTime()))
            ->execute();

        $this->assertSame(['Please select at least one redirect.'], $this->messages['error']);
        $this->assertSame([], $this->updates);
    }

    private function editPage(array $params): array
    {
        $titles = [];
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($t) use (&$titles) {
            $titles[] = (string) $t;
        });
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($config);
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);

        $result = (new Edit($this->context($params), $factory))->execute();
        return [$result, $page, $titles];
    }

    public function testEditTitleReflectsNewOrExistingRecord(): void
    {
        [$result, $page, $titles] = $this->editPage(['id' => '8']);
        $this->assertSame($page, $result);
        $this->assertSame(['Edit Redirect #8'], $titles);

        [, , $titles] = $this->editPage([]);
        $this->assertSame(['New Redirect'], $titles);
    }

    public function testAclUsesTheControllerResource(): void
    {
        $action = new Delete($this->context([], [], [], false), $this->resourceStub($this->connectionStub()), $this->cache());
        $method = new \ReflectionMethod(AbstractAction::class, '_isAllowed');

        $this->assertFalse($method->invoke($action));
        $this->assertSame(['Panth_Redirects::redirects'], $this->aclChecks);
    }
}
