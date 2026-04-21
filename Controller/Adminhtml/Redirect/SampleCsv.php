<?php
declare(strict_types=1);

namespace Panth\Redirects\Controller\Adminhtml\Redirect;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Panth\Redirects\Controller\Adminhtml\AbstractAction;

/**
 * Serves a ready-to-edit sample CSV so admins can download a template
 * file from the Import screen and fill in their own rows.
 */
class SampleCsv extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Redirects::redirects';

    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $csv = "store_id,match_type,pattern,target,status_code,priority,is_active\n"
             . "0,literal,/old-page.html,/new-page.html,301,10,1\n"
             . "0,literal,/retired-product.html,/category/replacement.html,301,10,1\n"
             . "1,literal,/hyva-only-old,/hyva-only-new,301,20,1\n"
             . "2,literal,/luma-only-old,/luma-only-new,302,20,1\n"
             . "0,regex,^/archive/([0-9]+)\$,/product/\$1,301,30,1\n"
             . "0,maintenance,/checkout-maintenance,\"We are offline for maintenance. Please try again later.\",503,5,1\n";

        return $this->fileFactory->create(
            'panth_redirects_sample.csv',
            $csv,
            \Magento\Framework\App\Filesystem\DirectoryList::VAR_DIR,
            'text/csv'
        );
    }
}
