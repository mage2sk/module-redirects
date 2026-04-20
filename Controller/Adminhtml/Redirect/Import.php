<?php
declare(strict_types=1);

namespace Panth\Redirects\Controller\Adminhtml\Redirect;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Panth\Redirects\Controller\Adminhtml\AbstractAction;
use Panth\Redirects\Model\Redirect\ImportExport;

/**
 * Uploads a CSV file and imports it via the ImportExport service.
 *
 * SECURITY
 * --------
 *  - POST-only, ACL-protected, FormKey-protected (via Magento\Backend\App\Action).
 *  - MIME type validated via finfo.
 *  - File extension validated.
 *  - Upload size validated against a hard 10 MB cap.
 *  - File moved into var/ (out of web root) using an unpredictable random
 *    name, parsed with fgetcsv() (never str_getcsv on raw body), then
 *    deleted on completion.
 *  - Loop detection runs inside the ImportExport service so invalid rows
 *    are skipped and reported rather than persisted.
 */
class Import extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Redirects::redirects';

    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public function __construct(
        Context $context,
        private readonly ImportExport $importExport,
        private readonly Filesystem $filesystem
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $files = $this->getRequest()->getFiles('import_file');

        if (!$files || empty($files['tmp_name']) || empty($files['name'])) {
            $this->messageManager->addErrorMessage(__('No file uploaded.'));
            return $resultRedirect->setPath('*/*/');
        }

        $origName = (string) $files['name'];
        $size     = (int) ($files['size'] ?? 0);
        $tmpName  = (string) $files['tmp_name'];

        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            $this->messageManager->addErrorMessage(__('Upload exceeds the allowed size.'));
            return $resultRedirect->setPath('*/*/');
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $this->messageManager->addErrorMessage(__('Only CSV files are allowed.'));
            return $resultRedirect->setPath('*/*/');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName);
        $allowedMimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
        if ($mime !== false && !in_array($mime, $allowedMimes, true)) {
            $this->messageManager->addErrorMessage(__('Invalid file type. Only CSV files are allowed.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $dest = $var->getAbsolutePath('panth_redirects_import_' . bin2hex(random_bytes(8)) . '.csv');
            if (!move_uploaded_file($tmpName, $dest)) {
                throw new \RuntimeException('Could not move uploaded file.');
            }

            try {
                $result = $this->importExport->import($dest, false);
            } finally {
                if (file_exists($dest)) {
                    @unlink($dest);
                }
            }

            if (($result['skipped'] ?? 0) > 0) {
                $this->messageManager->addWarningMessage(__('%1 row(s) skipped due to invalid data.', (int) $result['skipped']));
            }
            $this->messageManager->addSuccessMessage(__('%1 redirect(s) imported.', (int) $result['imported']));
            foreach ((array) ($result['errors'] ?? []) as $errMsg) {
                $this->messageManager->addNoticeMessage((string) $errMsg);
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $resultRedirect->setPath('*/*/');
    }
}
