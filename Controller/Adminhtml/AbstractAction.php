<?php
declare(strict_types=1);

namespace Panth\Redirects\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

/**
 * Shared base for Panth Redirects admin controllers. Every subclass MUST
 * override `ADMIN_RESOURCE` with the ACL node that protects the action.
 */
abstract class AbstractAction extends Action
{
    public const ADMIN_RESOURCE = 'Panth_Redirects::manage';

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(static::ADMIN_RESOURCE);
    }
}
