<?php
declare(strict_types=1);

namespace Panth\Redirects\Controller\Adminhtml\NotFoundLog;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Panth\Redirects\Controller\Adminhtml\AbstractAction;

class Delete extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Redirects::not_found_log';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        $resultRedirect = $this->resultRedirectFactory->create();
        if ($id > 0) {
            try {
                $this->resource->getConnection()->delete(
                    $this->resource->getTableName('panth_seo_404_log'),
                    ['log_id = ?' => $id]
                );
                $this->messageManager->addSuccessMessage(__('Log entry deleted.'));
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }
        return $resultRedirect->setPath('*/*/');
    }
}
