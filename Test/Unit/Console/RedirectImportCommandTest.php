<?php
declare(strict_types=1);

namespace Panth\Redirects\Test\Unit\Console;

use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Panth\Redirects\Console\Command\RedirectImportCommand;
use Panth\Redirects\Model\Redirect\ImportExport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RedirectImportCommandTest extends TestCase
{
    private array $calls = [];

    private function tester(?array $result, ?\Throwable $error = null, bool $areaThrows = false): CommandTester
    {
        $importExport = $this->createStub(ImportExport::class);
        $importExport->method('import')->willReturnCallback(function ($file, $dryRun) use ($result, $error) {
            $this->calls[] = [$file, $dryRun];
            if ($error !== null) {
                throw $error;
            }
            return $result;
        });

        $state = $this->createStub(State::class);
        if ($areaThrows) {
            $state->method('setAreaCode')->willThrowException(new LocalizedException(__('Area code is already set')));
        }

        return new CommandTester(new RedirectImportCommand($importExport, $state));
    }

    public function testCommandIsRegisteredUnderItsName(): void
    {
        $command = new RedirectImportCommand($this->createStub(ImportExport::class), $this->createStub(State::class));
        $this->assertSame('panth:redirects:import', $command->getName());
        $this->assertTrue($command->getDefinition()->getArgument('file')->isRequired());
        $this->assertTrue($command->getDefinition()->hasOption('dry-run'));
    }

    public function testImportReportsCountsAndErrors(): void
    {
        $tester = $this->tester(['imported' => 3, 'skipped' => 1, 'errors' => ['Line 4: invalid regex'], 'rows' => []]);

        $code = $tester->execute(['file' => '/tmp/r.csv']);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertSame([['/tmp/r.csv', false]], $this->calls);
        $this->assertStringContainsString('Import complete - imported=3 skipped=1 errors=1', $tester->getDisplay());
        $this->assertStringContainsString('Line 4: invalid regex', $tester->getDisplay());
    }

    public function testDryRunIsPassedThrough(): void
    {
        $tester = $this->tester(['imported' => 2, 'skipped' => 0, 'errors' => [], 'rows' => []], null, true);

        $tester->execute(['file' => 'r.csv', '--dry-run' => true]);

        $this->assertSame([['r.csv', true]], $this->calls);
        $this->assertStringContainsString('Dry-run complete - imported=2 skipped=0 errors=0', $tester->getDisplay());
    }

    public function testFailureReturnsAnErrorCode(): void
    {
        $tester = $this->tester(null, new LocalizedException(__('CSV file is empty.')));

        $this->assertSame(Command::FAILURE, $tester->execute(['file' => 'empty.csv']));
        $this->assertStringContainsString('CSV file is empty.', $tester->getDisplay());
    }
}
