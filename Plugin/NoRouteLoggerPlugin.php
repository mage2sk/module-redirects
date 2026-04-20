<?php
declare(strict_types=1);

namespace Panth\Redirects\Plugin;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Router\NoRouteHandlerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Model\Redirect\NotFoundLogger;
use Psr\Log\LoggerInterface;

/**
 * Logs 404 requests when the NoRouteHandler processes them.
 * This fires for ALL 404s regardless of how the no-route is handled.
 */
class NoRouteLoggerPlugin
{
    public function __construct(
        private readonly NotFoundLogger $notFoundLogger,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterProcess(
        NoRouteHandlerInterface $subject,
        bool $result,
        RequestInterface $request
    ): bool {
        try {
            if (!$this->config->isEnabled() || !$this->config->isLog404Enabled()) {
                return $result;
            }

            $storeId   = (int) $this->storeManager->getStore()->getId();
            $path      = (string) $request->getPathInfo();
            $referer   = (string) ($request->getServer('HTTP_REFERER') ?? '');
            $userAgent = (string) ($request->getServer('HTTP_USER_AGENT') ?? '');

            $this->notFoundLogger->log(
                $path,
                $storeId,
                $referer !== '' ? $referer : null,
                $userAgent !== '' ? $userAgent : null
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthRedirects] 404 logger plugin failed: ' . $e->getMessage());
        }

        return $result;
    }
}
