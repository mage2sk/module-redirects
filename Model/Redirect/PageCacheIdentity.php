<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

use Magento\Framework\DataObject\IdentityInterface;

class PageCacheIdentity implements IdentityInterface
{
    public const TAG = 'FPC';

    public function getIdentities()
    {
        return [self::TAG];
    }
}
