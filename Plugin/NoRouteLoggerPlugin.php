<?php
declare(strict_types=1);

namespace Panth\Redirects\Plugin;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Router\NoRouteHandlerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Redirects\Api\RedirectMatcherInterface;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Model\Redirect\NotFoundLogger;
use Psr\Log\LoggerInterface;

class NoRouteLoggerPlugin
{
    public function __construct(
        private readonly NotFoundLogger $notFoundLogger,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly RedirectMatcherInterface $matcher
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

            $storeId = (int) $this->storeManager->getStore()->getId();
            $path    = (string) $request->getPathInfo();

            if ($this->matcher->match($path, $storeId) !== null) {
                return $result;
            }

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
