<?php
declare(strict_types=1);

namespace Panth\Redirects\Plugin\Redirect;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Service\RedirectGuard;
use Psr\Log\LoggerInterface;

/**
 * 301-redirect common homepage aliases (/index.php, /home, /cms/index, etc.)
 * to the store root.
 */
class HomepageRedirectPlugin
{
    /** @var string[] */
    private const HOMEPAGE_ALIASES = [
        '/index.php',
        '/home',
        '/cms/index',
        '/cms/index/index',
    ];

    /**
     * HEAD is body-less and idempotent — included alongside GET so crawlers
     * and monitoring probes see the same 301 as a normal browser.
     */
    private const REDIRECTABLE_METHODS = ['GET', 'HEAD'];

    public function __construct(
        private readonly Config $config,
        private readonly HttpResponse $response,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly RedirectGuard $redirectGuard
    ) {
    }

    public function aroundDispatch(
        FrontControllerInterface $subject,
        callable $proceed,
        RequestInterface $request
    ): ResponseInterface|ResultInterface {
        try {
            if ($this->shouldRedirect($request)) {
                $baseUrl = rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/') . '/';

                $this->response->setRedirect($baseUrl, 301);
                $this->response->setNoCacheHeaders();
                $this->response->setHeader('X-Magento-Tags', '', true);
                $this->response->sendHeaders();

                return $this->response;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PanthRedirects] Homepage redirect plugin failed, proceeding normally',
                ['error' => $e->getMessage()]
            );
        }

        return $proceed($request);
    }

    private function shouldRedirect(RequestInterface $request): bool
    {
        if (!$this->config->isEnabled() || !$this->config->isHomepageRedirectEnabled()) {
            return false;
        }

        $method = strtoupper((string) $request->getMethod());
        if (!in_array($method, self::REDIRECTABLE_METHODS, true)) {
            return false;
        }

        $safe = $this->isSafeForHomepageRedirect($request);
        if (!$safe) {
            return false;
        }

        $path = $this->extractPath($request);
        if ($path === '' || $path === '/') {
            return false;
        }

        $normalised = strtolower(rtrim($path, '/'));
        return in_array($normalised, self::HOMEPAGE_ALIASES, true);
    }

    private function isSafeForHomepageRedirect(RequestInterface $request): bool
    {
        if (!method_exists($request, 'setMethod') || !method_exists($request, 'getMethod')) {
            return $this->redirectGuard->isSafeToRedirect($request);
        }

        $originalMethod = (string) $request->getMethod();
        if (strtoupper($originalMethod) === 'GET') {
            return $this->redirectGuard->isSafeToRedirect($request);
        }

        try {
            $request->setMethod('GET');
            return $this->redirectGuard->isSafeToRedirect($request);
        } finally {
            $request->setMethod($originalMethod);
        }
    }

    private function extractPath(RequestInterface $request): string
    {
        $path = (string) $request->getPathInfo();
        if ($path !== '' && $path !== '/') {
            return $path;
        }

        $uri = (string) $request->getRequestUri();
        if ($uri === '' || $uri === '/') {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        }

        if ($uri === '') {
            return $path;
        }

        $parsed = parse_url($uri, PHP_URL_PATH);
        if (!is_string($parsed) || $parsed === '') {
            return $path;
        }

        return $parsed;
    }
}
