<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Ui;

use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection as PlainCollection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Escaper;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Panth\Redirects\Model\ResourceModel\Redirect\Collection;
use Panth\Redirects\Model\ResourceModel\Redirect\CollectionFactory;
use Panth\Redirects\Ui\Component\Form\DataProvider\RedirectFormDataProvider;
use Panth\Redirects\Ui\Component\Listing\Column\EscapeTextColumn;
use Panth\Redirects\Ui\Component\Listing\Column\RedirectActions;
use Panth\Redirects\Ui\Component\Listing\Column\StatusCodeColumn;
use Panth\Redirects\Ui\Component\Listing\LikeFulltextFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListingAndFormTest extends TestCase
{
    private function escaper(): Escaper
    {
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES));
        $escaper->method('escapeHtmlAttr')->willReturnCallback(static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES));
        return $escaper;
    }

    private function args(): array
    {
        return [$this->createStub(ContextInterface::class), $this->createStub(UiComponentFactory::class)];
    }

    public function testEscapeColumnEscapesOnlyItsOwnField(): void
    {
        [$context, $factory] = $this->args();
        $column = new EscapeTextColumn($context, $factory, $this->escaper(), [], ['name' => 'pattern']);

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['pattern' => '<script>x</script>', 'target' => '<b>'],
            ['target' => '/no-pattern'],
            ['pattern' => null],
        ]]]);
        $items = $result['data']['items'];

        $this->assertSame('&lt;script&gt;x&lt;/script&gt;', $items[0]['pattern']);
        $this->assertSame('<b>', $items[0]['target']);
        $this->assertArrayNotHasKey('pattern', $items[1]);
        $this->assertSame('', $items[2]['pattern']);
    }

    public function testColumnsLeaveSourcesWithoutItemsAlone(): void
    {
        [$context, $factory] = $this->args();
        $source = ['data' => ['totalRecords' => 0]];

        $this->assertSame($source, (new EscapeTextColumn($context, $factory, $this->escaper()))->prepareDataSource($source));
        $this->assertSame($source, (new StatusCodeColumn($context, $factory, $this->escaper()))->prepareDataSource($source));
        $this->assertSame(
            $source,
            (new RedirectActions($context, $factory, $this->createStub(UrlInterface::class)))->prepareDataSource($source)
        );
    }

    public static function statusColorProvider(): array
    {
        return [
            'redirect'    => [301, '#8a5a00'],
            'client'      => [410, '#c41a1a'],
            'server'      => [503, '#c41a1a'],
            'success'     => [200, '#185b00'],
            'unknown'     => [0, '#333333'],
        ];
    }

    #[DataProvider('statusColorProvider')]
    public function testStatusCodeColumnColoursByClass(int $code, string $color): void
    {
        [$context, $factory] = $this->args();
        $column = new StatusCodeColumn($context, $factory, $this->escaper(), [], ['name' => 'status_code']);

        $items = $column->prepareDataSource(['data' => ['items' => [['status_code' => (string) $code]]]])['data']['items'];

        $this->assertSame(
            sprintf('<span style="color:%s;font-weight:600;">%d</span>', $color, $code),
            $items[0]['status_code']
        );
    }

    public function testActionsColumnAddsEditAndPostDeleteLinks(): void
    {
        [$context, $factory] = $this->args();
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route, $params = []) => 'https://admin.test/' . $route . '/id/' . $params['id']
        );
        $column = new RedirectActions($context, $factory, $url, [], ['name' => 'actions']);

        $items = $column->prepareDataSource(['data' => ['items' => [['redirect_id' => 3], ['pattern' => '/x']]]])['data']['items'];

        $this->assertSame('https://admin.test/panth_redirects/redirect/edit/id/3', $items[0]['actions']['edit']['href']);
        $this->assertSame('https://admin.test/panth_redirects/redirect/delete/id/3', $items[0]['actions']['delete']['href']);
        $this->assertTrue($items[0]['actions']['delete']['post']);
        $this->assertSame('Delete redirect', $items[0]['actions']['delete']['confirm']['title']);
        $this->assertArrayNotHasKey('actions', $items[1]);
    }

    private function dbCollection(array &$wheres): AbstractCollection
    {
        $connection = $this->createStub(Mysql::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn($id) => '`' . $id . '`');
        $connection->method('quoteInto')->willReturnCallback(
            static fn($text, $value) => str_replace('?', "'" . addslashes((string) $value) . "'", $text)
        );
        $select = $this->createStub(Select::class);
        $select->method('where')->willReturnCallback(function ($cond) use (&$wheres, &$select) {
            $wheres[] = $cond;
            return $select;
        });
        $collection = $this->createStub(AbstractCollection::class);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getSelect')->willReturn($select);
        return $collection;
    }

    private function filter(mixed $value): Filter
    {
        $filter = $this->createStub(Filter::class);
        $filter->method('getValue')->willReturn($value);
        return $filter;
    }

    public function testFulltextFilterOrsALikeAcrossConfiguredColumns(): void
    {
        $wheres = [];
        (new LikeFulltextFilter(['pattern', 'target', 42]))->apply($this->dbCollection($wheres), $this->filter('  sale_50% '));

        $this->assertSame(
            ["`pattern` LIKE '%sale\\\\_50\\\\%%' OR `target` LIKE '%sale\\\\_50\\\\%%'"],
            $wheres
        );
    }

    public function testFulltextFilterIgnoresBlankOrNonScalarValues(): void
    {
        $wheres = [];
        $filter = new LikeFulltextFilter(['pattern']);
        $filter->apply($this->dbCollection($wheres), $this->filter('   '));
        $filter->apply($this->dbCollection($wheres), $this->filter(['a']));

        $this->assertSame([], $wheres);
    }

    public function testFulltextFilterNeedsColumnsAndADatabaseCollection(): void
    {
        $wheres = [];
        (new LikeFulltextFilter([]))->apply($this->dbCollection($wheres), $this->filter('x'));
        $this->assertSame([], $wheres);

        // A non-database collection is silently ignored.
        (new LikeFulltextFilter(['pattern']))->apply($this->createStub(PlainCollection::class), $this->filter('x'));
        $this->assertSame([], $wheres);
    }

    public function testFulltextFilterCapsTheSearchTermAt200Characters(): void
    {
        $wheres = [];
        (new LikeFulltextFilter(['pattern']))->apply($this->dbCollection($wheres), $this->filter(str_repeat('a', 500)));

        $this->assertSame("`pattern` LIKE '%" . str_repeat('a', 200) . "%'", $wheres[0]);
    }

    private function formProvider(array $items): RedirectFormDataProvider
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn($items);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return new RedirectFormDataProvider('redirect_form', 'redirect_id', 'id', $factory);
    }

    public function testFormDataIsKeyedByRecordId(): void
    {
        $item = new DataObject(['id' => 5, 'pattern' => '/a']);
        $provider = $this->formProvider([$item]);

        $this->assertSame([5 => ['id' => 5, 'pattern' => '/a']], $provider->getData());
        $this->assertSame($provider->getData(), $provider->getData());
    }

    public function testNewFormGetsSensibleDefaults(): void
    {
        $this->assertSame(['' => [
            'match_type'  => 'literal',
            'status_code' => 301,
            'is_active'   => 1,
            'priority'    => 10,
            'store_id'    => 0,
        ]], $this->formProvider([])->getData());
    }
}
