<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;

/**
 * Builds database doubles that record every write and every WHERE clause.
 */
trait DbStubTrait
{
    /** @var array<int, array{0: string, 1: mixed}> */
    protected array $whereLog = [];

    /** @var array<int, array{table: string, data: array}> */
    protected array $inserts = [];

    /** @var array<int, array{table: string, data: array, where: mixed}> */
    protected array $updates = [];

    /** @var array<int, array{table: string, where: mixed}> */
    protected array $deletes = [];

    protected function selectStub(): Select
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'order', 'limit', 'joinLeft', 'join', 'columns', 'group'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('where')->willReturnCallback(
            function ($cond, $value = null) use (&$select) {
                $this->whereLog[] = [(string) $cond, $value];
                return $select;
            }
        );
        return $select;
    }

    /**
     * @param array<string, mixed> $config fetchAll, fetchOne, fetchPairs, query, tableExists, delete callables or values
     */
    protected function connectionStub(array $config = []): AdapterInterface
    {
        $connection = $this->createStub(Mysql::class);
        $connection->method('select')->willReturnCallback(fn() => $this->selectStub());

        foreach (['fetchAll', 'fetchOne', 'fetchPairs', 'query', 'isTableExists'] as $method) {
            if (!array_key_exists($method, $config)) {
                continue;
            }
            $value = $config[$method];
            if (is_callable($value) && !is_string($value)) {
                $connection->method($method)->willReturnCallback($value);
            } else {
                $connection->method($method)->willReturn($value);
            }
        }

        $connection->method('insert')->willReturnCallback(
            function ($table, array $data) use ($config) {
                if (isset($config['insertThrows'])) {
                    throw $config['insertThrows'];
                }
                $this->inserts[] = ['table' => $table, 'data' => $data];
                return 1;
            }
        );
        $connection->method('update')->willReturnCallback(
            function ($table, array $data, $where = '') use ($config) {
                if (isset($config['updateThrows'])) {
                    throw $config['updateThrows'];
                }
                $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
                return 1;
            }
        );
        $deleteResult = $config['deleteResult'] ?? 0;
        $connection->method('delete')->willReturnCallback(
            function ($table, $where = '') use ($config, &$deleteResult) {
                if (isset($config['deleteThrows'])) {
                    throw $config['deleteThrows'];
                }
                $this->deletes[] = ['table' => $table, 'where' => $where];
                return is_array($deleteResult) ? (int) array_shift($deleteResult) : (int) $deleteResult;
            }
        );
        $connection->method('lastInsertId')->willReturn($config['lastInsertId'] ?? '0');

        return $connection;
    }

    protected function resourceStub(AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn($name) => 'pfx_' . $name);
        return $resource;
    }

    protected function whereValue(string $cond): mixed
    {
        foreach ($this->whereLog as [$c, $v]) {
            if ($c === $cond) {
                return $v;
            }
        }
        return null;
    }
}
