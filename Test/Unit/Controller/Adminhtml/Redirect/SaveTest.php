<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Controller\Adminhtml\Redirect;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\Redirects\Controller\Adminhtml\Redirect\Save;
use Panth\Redirects\Model\Redirect\Loop;
use Panth\Redirects\Model\Redirect\Matcher;
use Panth\Redirects\Test\Unit\Controller\Adminhtml\ControllerTestCase;
use Panth\Redirects\Test\Unit\DbStubTrait;
use PHPUnit\Framework\Attributes\DataProvider;

class SaveTest extends ControllerTestCase
{
    use DbStubTrait;

    private array $cleaned = [];
    private array $loopCalls = [];

    private function save(array $post, array $params = [], array $loop = [], array $db = []): Save
    {
        $this->cleaned = [];
        $this->loopCalls = [];

        $loopDetector = $this->createStub(Loop::class);
        $loopDetector->method('detect')->willReturnCallback(function (...$args) use ($loop) {
            $this->loopCalls[] = $args;
            return $loop;
        });

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-03 10:00:00');

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('clean')->willReturnCallback(function ($tags) {
            $this->cleaned[] = $tags;
            return true;
        });

        return new Save(
            $this->context($params, $post),
            $this->resourceStub($this->connectionStub($db + ['lastInsertId' => '77'])),
            $dateTime,
            $loopDetector,
            $cache
        );
    }

    private function valid(array $overrides = []): array
    {
        return array_merge([
            'pattern'     => ' /old ',
            'target'      => ' /new ',
            'match_type'  => 'literal',
            'status_code' => '301',
            'store_id'    => '1',
        ], $overrides);
    }

    public function testEmptyPostGoesBackToTheGrid(): void
    {
        $this->save([])->execute();
        $this->assertSame('*/*/', $this->redirect['path']);
        $this->assertSame([], $this->inserts);
    }

    public function testNewRuleIsInsertedWithDefaults(): void
    {
        $this->save($this->valid())->execute();

        $this->assertCount(1, $this->inserts);
        $this->assertSame([
            'pattern'     => '/old',
            'target'      => '/new',
            'match_type'  => 'literal',
            'status_code' => 301,
            'store_id'    => 1,
            'is_active'   => 1,
            'priority'    => 10,
            'start_at'    => null,
            'finish_at'   => null,
            'updated_at'  => '2026-10-03 10:00:00',
            'created_at'  => '2026-10-03 10:00:00',
        ], $this->inserts[0]['data']);
        $this->assertSame([[Matcher::CACHE_TAG]], $this->cleaned);
        $this->assertSame(['Redirect saved.'], $this->messages['success']);
        $this->assertSame('*/*/', $this->redirect['path']);
        $this->assertSame(['/old', '/new', 1, null], $this->loopCalls[0]);
    }

    public function testSaveAndContinueReturnsToTheNewRecord(): void
    {
        $this->save($this->valid(), ['back' => 'edit'])->execute();
        $this->assertSame(['path' => '*/*/edit', 'params' => ['id' => 77]], $this->redirect);
    }

    public function testExistingRuleIsUpdatedAndExcludedFromLoopCheck(): void
    {
        $this->save($this->valid([
            'redirect_id' => '12',
            'is_active'   => '0',
            'priority'    => '3',
            'start_at'    => '2026-01-01 00:00:00',
        ]))->execute();

        $this->assertSame([], $this->inserts);
        $this->assertSame(['redirect_id = ?' => 12], $this->updates[0]['where']);
        $this->assertSame(0, $this->updates[0]['data']['is_active']);
        $this->assertSame(3, $this->updates[0]['data']['priority']);
        $this->assertSame('2026-01-01 00:00:00', $this->updates[0]['data']['start_at']);
        $this->assertArrayNotHasKey('created_at', $this->updates[0]['data']);
        $this->assertSame(12, $this->loopCalls[0][3]);
    }

    public function testLongValuesAreCappedAt1024Characters(): void
    {
        $this->save($this->valid(['pattern' => '/' . str_repeat('a', 2000)]))->execute();
        $this->assertSame(1024, mb_strlen($this->inserts[0]['data']['pattern']));
    }

    public static function invalidProvider(): array
    {
        return [
            'bad match type'  => [['match_type' => 'glob'], 'Invalid match type.'],
            'bad status'      => [['status_code' => '200'], 'Invalid status code.'],
            'js target'       => [['target' => 'javascript:alert(1)'], 'Target URL must not use javascript:, data:, or vbscript: protocols.'],
            'data target'     => [['target' => 'DATA:text/html,x'], 'Target URL must not use javascript:, data:, or vbscript: protocols.'],
            'empty pattern'   => [['pattern' => '  '], 'Pattern and Target URL are required.'],
            'empty target'    => [['target' => ''], 'Pattern and Target URL are required.'],
            'broken regex'    => [['match_type' => 'regex', 'pattern' => '^/a(['], 'Pattern is not a valid regular expression.'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidInputReturnsToTheFormWithAnError(array $overrides, string $error): void
    {
        $this->save($this->valid($overrides + ['redirect_id' => '4']))->execute();

        $this->assertSame([$error], $this->messages['error']);
        $this->assertSame(['path' => '*/*/edit', 'params' => ['id' => 4]], $this->redirect);
        $this->assertSame([], $this->inserts);
        $this->assertSame([], $this->updates);
    }

    public function testLoopIsReportedAndNothingIsSaved(): void
    {
        $this->save($this->valid(), [], ['/old', '/new', '/old'])->execute();

        $this->assertSame(['Redirect loop detected: /old -> /new -> /old'], $this->messages['error']);
        $this->assertSame([], $this->inserts);
    }

    public function testRegexAndMaintenanceRulesSkipTheLoopCheck(): void
    {
        $this->save($this->valid(['match_type' => 'regex', 'pattern' => '^/a/(.*)$']))->execute();
        $this->save($this->valid(['match_type' => 'maintenance', 'status_code' => '503']))->execute();

        $this->assertSame([], $this->loopCalls);
        $this->assertCount(2, $this->inserts);
    }

    public function testDatabaseErrorIsShownAndFormIsKept(): void
    {
        $this->save($this->valid(), [], [], ['insertThrows' => new \RuntimeException('Duplicate entry')])->execute();

        $this->assertSame(['Duplicate entry'], $this->messages['error']);
        $this->assertSame(['path' => '*/*/edit', 'params' => ['id' => 0]], $this->redirect);
        $this->assertSame([], $this->cleaned);
    }
}
