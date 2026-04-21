<?php
declare(strict_types=1);

namespace Panth\Redirects\Observer\Redirect;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Redirects\Api\RedirectMatcherInterface;
use Panth\Redirects\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Logs 404s on cms_index_noroute dispatch.
 */
class NoRoute implements ObserverInterface
{
    public function __construct(
        private readonly RedirectMatcherInterface $matcher,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled() || !$this->config->isLog404Enabled()) {
                return;
            }
            $storeId = (int) $this->storeManager->getStore()->getId();
            $path    = (string) $this->request->getPathInfo();

            // `cms_index_noroute` fires before `controller_action_predispatch`
            // on the noroute action, so our Predispatch observer hasn't yet
            // had a chance to issue the 301. Skip logging when a matching
            // redirect rule exists — that path is not a real 404.
            if ($this->matcher->match($path, $storeId) !== null) {
                return;
            }

            $referer   = (string) ($this->request->getServer('HTTP_REFERER') ?? '');
            $userAgent = (string) ($this->request->getServer('HTTP_USER_AGENT') ?? '');
            $this->matcher->log404(
                $path,
                $storeId,
                $referer !== '' ? $referer : null,
                $userAgent !== '' ? $userAgent : null
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthRedirects] 404 logger failed: ' . $e->getMessage());
        }
    }
}
