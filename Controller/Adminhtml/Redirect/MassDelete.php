<?php
declare(strict_types=1);

namespace Panth\Redirects\Controller\Adminhtml\Redirect;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Ui\Component\MassAction\Filter;
use Panth\Redirects\Controller\Adminhtml\AbstractAction;
use Panth\Redirects\Model\Redirect\Matcher;
use Panth\Redirects\Model\ResourceModel\Redirect\CollectionFactory;

class MassDelete extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Redirects::redirects';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create()->setPath('*/*/');
        try {
            $ids = array_map('intval', $this->filter->getCollection($this->collectionFactory->create())->getAllIds());
            $ids = array_values(array_filter($ids));
            if ($ids === []) {
                $this->messageManager->addErrorMessage(__('Please select at least one redirect.'));
                return $resultRedirect;
            }
            $deleted = $this->resource->getConnection()->delete(
                $this->resource->getTableName('panth_seo_redirect'),
                ['redirect_id IN (?)' => $ids]
            );
            $this->cache->clean([Matcher::CACHE_TAG]);
            $this->messageManager->addSuccessMessage(__('A total of %1 redirect(s) have been deleted.', $deleted));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $resultRedirect;
    }
}
