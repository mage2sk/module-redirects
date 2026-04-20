<?php
declare(strict_types=1);

namespace Panth\Redirects\Plugin\Redirect;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Service\RedirectGuard;
use Psr\Log\LoggerInterface;

/**
 * 301-redirect URLs with a trailing slash to the version without.
 */
class TrailingSlashRedirectPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpResponse $response,
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
                $uri = (string) $request->getRequestUri();
                $stripped = $this->stripTrailingSlash($uri);

                $this->response->setRedirect($stripped, 301);
                $this->response->sendHeaders();

                return $this->response;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PanthRedirects] Trailing slash redirect plugin failed, proceeding normally',
                ['error' => $e->getMessage()]
            );
        }

        return $proceed($request);
    }

    private function shouldRedirect(RequestInterface $request): bool
    {
        if (!$this->redirectGuard->isSafeToRedirect($request)) {
            return false;
        }

        $uri = (string) $request->getRequestUri();
        if ($uri === '' || $uri === '/') {
            return false;
        }

        if (!$this->config->isEnabled() || !$this->config->canonicalRemoveTrailingSlash()) {
            return false;
        }

        $parsed = parse_url($uri);
        $path = $parsed['path'] ?? '';

        return $path !== '/' && $path !== '' && str_ends_with($path, '/');
    }

    private function stripTrailingSlash(string $uri): string
    {
        $parsed = parse_url($uri);
        $path = rtrim($parsed['path'] ?? '/', '/');
        if ($path === '') {
            $path = '/';
        }
        $query = $parsed['query'] ?? '';

        return $query !== '' ? $path . '?' . $query : $path;
    }
}
