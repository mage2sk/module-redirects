<?php
declare(strict_types=1);

namespace Panth\Redirects\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Panth\Redirects\Service\InstallReporter;

class RecurringData implements InstallDataInterface
{
    public function __construct(
        private readonly InstallReporter $reporter
    ) {
    }

    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context): void
    {
        $this->reporter->reportInstall();
    }
}
