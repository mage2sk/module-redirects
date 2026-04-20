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

class LowercaseRedirectPlugin
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
                $lowered = $this->lowercaseUri($uri);

                $this->response->setRedirect($lowered, 301);
                $this->response->sendHeaders();

                return $this->response;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PanthRedirects] Lowercase redirect plugin failed, proceeding normally',
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

        if (!$this->config->isEnabled() || !$this->config->isLowercaseRedirectEnabled()) {
            return false;
        }

        $parsed = parse_url($uri);
        $path = $parsed['path'] ?? '';

        return $path !== '' && $path !== strtolower($path);
    }

    private function lowercaseUri(string $uri): string
    {
        $parsed = parse_url($uri);
        $path = strtolower($parsed['path'] ?? '/');
        $query = $parsed['query'] ?? '';

        return $query !== '' ? $path . '?' . $query : $path;
    }
}
