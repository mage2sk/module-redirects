<?php
declare(strict_types=1);

namespace Panth\Redirects\Plugin\UrlRewrite;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Controller\Router;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Panth\Redirects\Service\RedirectGuard;

class RouterXhrGuard
{
    public function __construct(
        private readonly RedirectGuard $redirectGuard,
        private readonly UrlFinderInterface $urlFinder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function aroundMatch(
        Router $subject,
        callable $proceed,
        RequestInterface $request
    ): ?ActionInterface {
        if (!$this->redirectGuard->isSafeToRedirect($request) && $this->isRedirectRewrite($request)) {
            return null;
        }
        return $proceed($request);
    }

    private function isRedirectRewrite(RequestInterface $request): bool
    {
        try {
            $rewrite = $this->urlFinder->findOneByData([
                UrlRewrite::REQUEST_PATH => ltrim((string) $request->getPathInfo(), '/'),
                UrlRewrite::STORE_ID => (int) $this->storeManager->getStore()->getId(),
            ]);
        } catch (\Throwable) {
            return false;
        }
        return $rewrite !== null && (int) $rewrite->getRedirectType() > 0;
    }
}
