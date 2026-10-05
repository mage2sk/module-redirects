<?php
declare(strict_types=1);

namespace Panth\Redirects\Controller\Adminhtml\Redirect;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Panth\Redirects\Controller\Adminhtml\AbstractAction;

class NewAction extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Redirects::redirects';

    public function __construct(
        Context $context,
        private readonly ForwardFactory $forwardFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        return $this->forwardFactory->create()->forward('edit');
    }
}
