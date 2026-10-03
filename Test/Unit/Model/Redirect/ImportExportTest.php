<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Model\Redirect;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Panth\Redirects\Model\Redirect\ImportExport;
use Panth\Redirects\Model\Redirect\Loop;
use Panth\Redirects\Model\Redirect\Matcher;
use Panth\Redirects\Test\Unit\DbStubTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportExportTest extends TestCase
{
    use DbStubTrait;

    private const HEADER = "store_id,match_type,pattern,target,status_code,priority,is_active\n";

    private array $files = [];
    private array $cleaned = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->files = [];
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'redir');
        file_put_contents($path, $content);
        $this->files[] = $path;
        return $path;
    }

    private function service(array $loops = [], array $existing = [], array $exportRows = []): ImportExport
    {
        $connection = $this->connectionStub([
            'fetchPairs' => $existing,
            'query'      => function () use ($exportRows) {
                $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
                $statement->method('fetch')->willReturnCallback(
                    static function () use (&$exportRows) {
                        return array_shift($exportRows) ?? false;
                    }
                );
                return $statement;
            },
        ]);

        $loop = $this->createStub(Loop::class);
        $loop->method('detect')->willReturnCallback(
            static fn($from, $to) => $loops[$from] ?? []
        );

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('clean')->willReturnCallback(function ($tags) {
            $this->cleaned[] = $tags;
            return true;
        });

        return new ImportExport(
            $this->resourceStub($connection),
            $loop,
            $this->createStub(LoggerInterface::class),
            $cache
        );
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('CSV file not found or not readable');
        $this->service()->import(sys_get_temp_dir() . '/does-not-exist-' . uniqid() . '.csv');
    }

    public function testEmptyFileIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('CSV file is empty.');
        $this->service()->import($this->csv(''));
    }

    public function testMissingColumnsAreNamed(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Missing CSV columns: status_code,priority,is_active');
        $this->service()->import($this->csv("store_id,match_type,pattern,target\n"));
    }

    public function testHeaderIsCaseAndWhitespaceInsensitive(): void
    {
        $file = $this->csv(" Store_ID ,MATCH_TYPE,Pattern,Target,Status_Code,Priority,Is_Active\n0,literal,/a,/b,301,10,1\n");
        $this->assertSame(1, $this->service()->import($file)['imported']);
    }

    public function testValidRowsAreInsertedAndCacheIsCleanedOnce(): void
    {
        $file = $this->csv(self::HEADER
            . "0,literal,/old,/new,301,10,1\n"
            . "\n"
            . "1,regex,^/a/(.*)$,/b/$1,302,5,0\n");

        $result = $this->service()->import($file);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $this->inserts);
        $this->assertSame([
            'store_id'    => 0,
            'match_type'  => 'literal',
            'pattern'     => '/old',
            'target'      => '/new',
            'status_code' => 301,
            'priority'    => 10,
            'is_active'   => 1,
        ], $this->inserts[0]['data']);
        $this->assertSame(0, $this->inserts[1]['data']['is_active']);
        $this->assertSame([[Matcher::CACHE_TAG]], $this->cleaned);
    }

    public function testExistingPatternIsUpdatedAndLosesItsAutoFlag(): void
    {
        $file = $this->csv(self::HEADER . "0,literal,/old,/newer,301,10,1\n");

        $this->service([], [9 => '/old'])->import($file);

        $this->assertSame([], $this->inserts);
        $this->assertCount(1, $this->updates);
        $this->assertSame(['redirect_id = ?' => 9], $this->updates[0]['where']);
        $this->assertSame(0, $this->updates[0]['data']['is_auto_generated']);
        $this->assertSame('/newer', $this->updates[0]['data']['target']);
    }

    public function testDryRunValidatesWithoutWriting(): void
    {
        $file = $this->csv(self::HEADER . "0,literal,/old,/new,301,10,1\n");

        $result = $this->service()->import($file, true);

        $this->assertSame(1, $result['imported']);
        $this->assertSame('/old', $result['rows'][0]['pattern']);
        $this->assertSame([], $this->inserts);
        $this->assertSame([], $this->updates);
        $this->assertSame([], $this->cleaned);
    }

    public function testInvalidRowsAreSkippedWithLineNumbers(): void
    {
        $file = $this->csv(self::HEADER
            . "0,wildcard,/a,/b,301,10,1\n"
            . "0,literal,,/b,301,10,1\n"
            . "0,literal,/a,javascript:alert(1),301,10,1\n"
            . "0,literal,/a,/b,404,10,1\n"
            . "0,regex,^/a(,/b,301,10,1\n"
            . "0,maintenance,/m,Back soon,503,10,1\n");

        $result = $this->service()->import($file);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(5, $result['skipped']);
        $this->assertSame([
            'Line 2: invalid match_type',
            'Line 3: empty pattern/target',
            'Line 4: dangerous URI scheme in target',
            'Line 5: invalid status_code 404',
            'Line 6: invalid regex',
        ], $result['errors']);
        $this->assertSame('maintenance', $this->inserts[0]['data']['match_type']);
    }

    public function testLiteralLoopsAreSkipped(): void
    {
        $file = $this->csv(self::HEADER
            . "0,literal,/a,/b,301,10,1\n"
            . "0,regex,/a,/b,301,10,1\n");

        $result = $this->service(['/a' => ['/a', '/b', '/a']])->import($file);

        $this->assertSame(['Line 2: loop detected (/a -> /b -> /a)'], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame('regex', $this->inserts[0]['data']['match_type']);
    }

    public function testFormulaPrefixesAreStrippedBeforeSaving(): void
    {
        $file = $this->csv(self::HEADER . "0,literal,'=/formula,+/target,301,10,1\n");

        $this->service()->import($file);

        $this->assertSame('/formula', $this->inserts[0]['data']['pattern']);
        $this->assertSame('/target', $this->inserts[0]['data']['target']);
    }

    public function testNothingImportedMeansNoCacheClean(): void
    {
        $this->service()->import($this->csv(self::HEADER . "0,bogus,/a,/b,301,10,1\n"));
        $this->assertSame([], $this->cleaned);
    }

    public function testExportWritesHeaderAndEscapesFormulas(): void
    {
        $rows = [
            ['store_id' => '1', 'match_type' => 'literal', 'pattern' => '/a', 'target' => '=HYPERLINK(1)',
             'status_code' => '301', 'priority' => '10', 'is_active' => '1'],
            ['store_id' => '0', 'match_type' => 'regex', 'pattern' => '-x', 'target' => '/b',
             'status_code' => '302', 'priority' => '5', 'is_active' => '0'],
        ];
        $stream = fopen('php://memory', 'w+');

        $count = $this->service([], [], $rows)->exportToStream($stream, [1, 0]);

        rewind($stream);
        $lines = explode("\n", trim(stream_get_contents($stream)));
        fclose($stream);

        $this->assertSame(2, $count);
        $this->assertSame(implode(',', ImportExport::HEADER), $lines[0]);
        $this->assertSame("1,literal,/a,'=HYPERLINK(1),301,10,1", $lines[1]);
        $this->assertSame("0,regex,'-x,/b,302,5,0", $lines[2]);
        $this->assertSame([1, 0], $this->whereValue('store_id IN (?)'));
    }

    public function testExportWithoutStoreFilterHasNoWhereClause(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertSame(0, $this->service()->exportToStream($stream, []));
        fclose($stream);
        $this->assertNull($this->whereValue('store_id IN (?)'));
    }

    public function testExportRejectsNonStreams(): void
    {
        $this->expectException(LocalizedException::class);
        $this->service()->exportToStream('not a stream');
    }
}
