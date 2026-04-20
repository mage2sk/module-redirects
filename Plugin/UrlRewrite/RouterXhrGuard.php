<?php
declare(strict_types=1);

namespace Panth\Redirects\Plugin\UrlRewrite;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\UrlRewrite\Controller\Router;
use Panth\Redirects\Service\RedirectGuard;

/**
 * Block Magento core's UrlRewrite router from issuing a 301 on XHR / non-GET
 * requests.
 */
class RouterXhrGuard
{
    public function __construct(
        private readonly RedirectGuard $redirectGuard
    ) {
    }

    public function aroundMatch(
        Router $subject,
        callable $proceed,
        RequestInterface $request
    ): ?ActionInterface {
        if (!$this->redirectGuard->isSafeToRedirect($request)) {
            return null;
        }
        return $proceed($request);
    }
}
