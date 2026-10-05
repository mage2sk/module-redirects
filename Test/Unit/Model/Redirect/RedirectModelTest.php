<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Model\Redirect;

use Panth\Redirects\Model\Redirect\RedirectModel;
use PHPUnit\Framework\TestCase;

class RedirectModelTest extends TestCase
{
    private function model(array $data = []): RedirectModel
    {
        $model = (new \ReflectionClass(RedirectModel::class))->newInstanceWithoutConstructor();
        $model->setData($data);
        return $model;
    }

    public function testRedirectIdIsNullUntilPersisted(): void
    {
        $this->assertNull($this->model()->getRedirectId());
        $this->assertSame(12, $this->model(['redirect_id' => '12'])->getRedirectId());
    }

    public function testStatusCodeDefaultsTo301WhenEmpty(): void
    {
        $this->assertSame(301, $this->model()->getStatusCode());
        $this->assertSame(301, $this->model(['status_code' => 0])->getStatusCode());
        $this->assertSame(410, $this->model(['status_code' => '410'])->getStatusCode());
    }

    public function testTypedAccessorsCastRawRowValues(): void
    {
        $model = $this->model([
            'store_id'   => '2',
            'match_type' => 'regex',
            'pattern'    => '^/a',
            'target'     => '/b',
            'priority'   => '7',
            'is_active'  => '0',
            'hit_count'  => '15',
        ]);
        $this->assertSame(2, $model->getStoreId());
        $this->assertSame('regex', $model->getMatchType());
        $this->assertSame('^/a', $model->getPattern());
        $this->assertSame('/b', $model->getTarget());
        $this->assertSame(7, $model->getPriority());
        $this->assertFalse($model->isActive());
        $this->assertSame(15, $model->getHitCount());
        $this->assertNull($model->getLastHitAt());
    }

    public function testSettersRoundTrip(): void
    {
        $model = $this->model()
            ->setStoreId(1)
            ->setMatchType('literal')
            ->setPattern('/x')
            ->setTarget('/y')
            ->setStatusCode(302)
            ->setPriority(3)
            ->setIsActive(true)
            ->setHitCount(4)
            ->setLastHitAt('2026-01-01 00:00:00');
        $this->assertSame(302, $model->getStatusCode());
        $this->assertTrue($model->isActive());
        $this->assertSame('2026-01-01 00:00:00', $model->getLastHitAt());
        $this->assertSame('/y', $model->getTarget());
        $this->assertSame(1, $model->getStoreId());
    }
}
