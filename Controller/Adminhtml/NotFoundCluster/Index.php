<?php
declare(strict_types=1);

namespace Panth\Redirects\Controller\Adminhtml\NotFoundCluster;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Panth\Redirects\Controller\Adminhtml\AbstractAction;

class Index extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Redirects::not_found_cluster';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_Redirects::not_found_cluster');
        $page->getConfig()->getTitle()->prepend(__('404 Clusters'));
        return $page;
    }
}
