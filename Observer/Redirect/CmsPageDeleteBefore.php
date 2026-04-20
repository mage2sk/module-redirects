<?php
declare(strict_types=1);

namespace Panth\Redirects\Observer\Redirect;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Model\Redirect\AutoRedirectService;
use Psr\Log\LoggerInterface;

/**
 * Observer on `model_delete_before` (CMS page). Creates a 301 redirect
 * from the CMS page identifier to the homepage.
 */
class CmsPageDeleteBefore implements ObserverInterface
{
    public function __construct(
        private readonly AutoRedirectService $autoRedirectService,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled() || !$this->config->isAutoRedirectEnabled()) {
                return;
            }

            $event = $observer->getEvent();
            $page = $event->getObject() ?? $event->getPage();
            if (!$page instanceof PageInterface || !$page->getId()) {
                return;
            }

            $identifier = (string) $page->getIdentifier();
            if ($identifier === '' || $identifier === 'home' || $identifier === 'no-route') {
                return;
            }

            $storeIds = $page->getStoreId();
            if (!is_array($storeIds)) {
                $storeIds = [(int) $storeIds];
            }

            foreach ($storeIds as $storeId) {
                $this->autoRedirectService->createRedirect($identifier, '/', (int) $storeId);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[PanthRedirects] CmsPageDeleteBefore observer failed',
                ['error' => $e->getMessage()]
            );
        }
    }
}
