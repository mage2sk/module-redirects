<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Model\Redirect;

use Panth\Redirects\Model\Redirect\Loop;
use Panth\Redirects\Test\Unit\DbStubTrait;
use PHPUnit\Framework\TestCase;

class LoopTest extends TestCase
{
    use DbStubTrait;

    private function loop(array $rows): Loop
    {
        return new Loop($this->resourceStub($this->connectionStub(['fetchAll' => $rows])));
    }

    public function testSelfRedirectIsReportedWithoutQueryingTheDatabase(): void
    {
        $this->assertSame(['/a', '/a'], $this->loop([])->detect('/a/', 'a?x=1', 1));
        $this->assertSame([], $this->whereLog);
    }

    public function testNoExistingRulesMeansNoLoop(): void
    {
        $this->assertSame([], $this->loop([])->detect('/a', '/b', 1));
    }

    public function testTwoHopCycleIsDetected(): void
    {
        $loop = $this->loop([['redirect_id' => 1, 'pattern' => '/b', 'target' => '/a']]);
        $this->assertSame(['/a', '/b', '/a'], $loop->detect('/a', '/b', 1));
    }

    public function testAbsoluteTargetsAreReducedToTheirPath(): void
    {
        $loop = $this->loop([['redirect_id' => 1, 'pattern' => '/b/', 'target' => 'https://shop.test/a/']]);
        $this->assertSame(['/a', '/b', '/a'], $loop->detect('/a', 'https://shop.test/b?utm=1', 1));
    }

    public function testChainThatEndsDoesNotCount(): void
    {
        $loop = $this->loop([
            ['redirect_id' => 1, 'pattern' => '/b', 'target' => '/c'],
            ['redirect_id' => 2, 'pattern' => '/c', 'target' => '/d'],
        ]);
        $this->assertSame([], $loop->detect('/a', '/b', 1));
    }

    public function testVeryLongChainIsTreatedAsALoop(): void
    {
        $rows = [];
        for ($i = 1; $i <= 40; $i++) {
            $rows[] = ['redirect_id' => $i, 'pattern' => '/p' . $i, 'target' => '/p' . ($i + 1)];
        }
        $chain = $this->loop($rows)->detect('/p0', '/p1', 1);
        $this->assertCount(27, $chain);
        $this->assertSame('/p0', $chain[0]);
    }

    public function testEditedRuleIsExcludedAndStoreScopeIncludesDefault(): void
    {
        $this->loop([])->detect('/a', '/b', 4, 99);
        $this->assertSame(99, $this->whereValue('redirect_id <> ?'));
        $this->assertSame([0, 4], $this->whereValue('store_id IN (?)'));
        $this->assertSame('literal', $this->whereValue('match_type = ?'));
    }

    public function testNewRuleDoesNotAddTheExclusionClause(): void
    {
        $this->loop([])->detect('/a', '/b', 1);
        $this->assertNull($this->whereValue('redirect_id <> ?'));
    }
}
