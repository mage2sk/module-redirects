<?php
declare(strict_types=1);

namespace Panth\Redirects\Controller\Adminhtml\Redirect;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Panth\Redirects\Controller\Adminhtml\AbstractAction;
use Panth\Redirects\Model\Redirect\ImportExport;

class Export extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Redirects::redirects';

    public function __construct(
        Context $context,
        private readonly ImportExport $importExport,
        private readonly FileFactory $fileFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            $this->messageManager->addErrorMessage(__('Could not open a temporary stream.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
        $this->importExport->exportToStream($handle);
        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $this->fileFactory->create(
            'panth_redirects_' . date('Ymd_His') . '.csv',
            $content,
            DirectoryList::VAR_DIR,
            'text/csv'
        );
    }
}
