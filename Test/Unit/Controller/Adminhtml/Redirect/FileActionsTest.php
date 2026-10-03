<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Controller\Adminhtml\Redirect;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Panth\Redirects\Controller\Adminhtml\Redirect\Export;
use Panth\Redirects\Controller\Adminhtml\Redirect\Import;
use Panth\Redirects\Controller\Adminhtml\Redirect\SampleCsv;
use Panth\Redirects\Model\Redirect\ImportExport;
use Panth\Redirects\Model\Redirect\Loop;
use Panth\Redirects\Test\Unit\Controller\Adminhtml\ControllerTestCase;
use Panth\Redirects\Test\Unit\DbStubTrait;
use Psr\Log\LoggerInterface;

class FileActionsTest extends ControllerTestCase
{
    use DbStubTrait;

    private array $files = [];
    private array $downloads = [];
    private int $importCalls = 0;

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->files = [];
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'redirup');
        file_put_contents($path, $content);
        $this->files[] = $path;
        return $path;
    }

    private function fileFactory(): FileFactory
    {
        $factory = $this->createStub(FileFactory::class);
        $factory->method('create')->willReturnCallback(function (...$args) {
            $this->downloads[] = $args;
            return $this->createStub(ResponseInterface::class);
        });
        return $factory;
    }

    private function import(array $files): Import
    {
        $importExport = $this->createStub(ImportExport::class);
        $importExport->method('import')->willReturnCallback(function () {
            $this->importCalls++;
            return ['imported' => 0, 'skipped' => 0, 'errors' => [], 'rows' => []];
        });

        $var = $this->createStub(WriteInterface::class);
        $var->method('getAbsolutePath')->willReturnCallback(
            fn($path = null) => sys_get_temp_dir() . '/' . $path
        );
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($var);

        return new Import($this->context([], [], ['import_file' => $files]), $importExport, $filesystem);
    }

    public function testImportWithoutFileShowsAnError(): void
    {
        $import = new Import(
            $this->context(),
            $this->createStub(ImportExport::class),
            $this->createStub(Filesystem::class)
        );
        $import->execute();

        $this->assertSame(['No file uploaded.'], $this->messages['error']);
        $this->assertSame('*/*/', $this->redirect['path']);
    }

    public function testImportRejectsEmptyAndOversizedUploads(): void
    {
        $this->import(['name' => 'a.csv', 'tmp_name' => '/tmp/x', 'size' => 0])->execute();
        $this->assertSame(['Upload exceeds the allowed size.'], $this->messages['error']);

        $this->import(['name' => 'a.csv', 'tmp_name' => '/tmp/x', 'size' => 10 * 1024 * 1024 + 1])->execute();
        $this->assertSame(['Upload exceeds the allowed size.'], $this->messages['error']);
        $this->assertSame(0, $this->importCalls);
    }

    public function testImportRejectsNonCsvExtensions(): void
    {
        $this->import(['name' => 'redirects.xlsx', 'tmp_name' => '/tmp/x', 'size' => 10])->execute();
        $this->assertSame(['Only CSV files are allowed.'], $this->messages['error']);
    }

    public function testImportRejectsBinaryContentDisguisedAsCsv(): void
    {
        $png = $this->tempFile("\x89PNG\r\n\x1a\n" . str_repeat("\0", 64));
        $this->import(['name' => 'fake.csv', 'tmp_name' => $png, 'size' => 72])->execute();

        $this->assertSame(['Invalid file type. Only CSV files are allowed.'], $this->messages['error']);
        $this->assertSame(0, $this->importCalls);
    }

    public function testImportRefusesFilesThatWereNotUploadedOverHttp(): void
    {
        $csv = $this->tempFile("store_id,match_type\n0,literal\n");
        $this->import(['name' => 'ok.CSV', 'tmp_name' => $csv, 'size' => 30])->execute();

        $this->assertSame(['Could not move uploaded file.'], $this->messages['error']);
        $this->assertSame(0, $this->importCalls);
        $this->assertFileExists($csv);
    }

    public function testExportStreamsTheCsvAsADownload(): void
    {
        $importExport = $this->createStub(ImportExport::class);
        $importExport->method('exportToStream')->willReturnCallback(static function ($stream) {
            fwrite($stream, "store_id\n1\n");
            return 1;
        });

        (new Export($this->context(), $importExport, $this->fileFactory()))->execute();

        $this->assertCount(1, $this->downloads);
        [$name, $content, $dir, $type] = $this->downloads[0];
        $this->assertMatchesRegularExpression('/^panth_redirects_\d{8}_\d{6}\.csv$/', $name);
        $this->assertSame("store_id\n1\n", $content);
        $this->assertSame(DirectoryList::VAR_DIR, $dir);
        $this->assertSame('text/csv', $type);
    }

    public function testSampleCsvIsAValidImportFile(): void
    {
        (new SampleCsv($this->context(), $this->fileFactory()))->execute();
        [$name, $content] = $this->downloads[0];
        $this->assertSame('panth_redirects_sample.csv', $name);

        $loop = $this->createStub(Loop::class);
        $loop->method('detect')->willReturn([]);
        $importer = new ImportExport(
            $this->resourceStub($this->connectionStub()),
            $loop,
            $this->createStub(LoggerInterface::class),
            $this->createStub(CacheInterface::class)
        );
        $result = $importer->import($this->tempFile($content), true);

        $this->assertSame(6, $result['imported']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(
            ['literal', 'literal', 'literal', 'literal', 'regex', 'maintenance'],
            array_column($result['rows'], 'match_type')
        );
    }
}
