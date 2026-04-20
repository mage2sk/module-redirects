<?php
declare(strict_types=1);

namespace Panth\Redirects\Controller\Adminhtml\Redirect;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Panth\Redirects\Controller\Adminhtml\AbstractAction;

/**
 * Delete a redirect row. POST-only (FormKey-protected) so a drive-by
 * GET from a logged-in admin's browser can't silently wipe a rule.
 */
class Delete extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Redirects::redirects';

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
                    $this->resource->getTableName('panth_seo_redirect'),
                    ['redirect_id = ?' => $id]
                );
                $this->messageManager->addSuccessMessage(__('Redirect deleted.'));
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }
        return $resultRedirect->setPath('*/*/');
    }
}
