<?php
declare(strict_types=1);

namespace Panth\Redirects\Observer\Redirect;

use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Redirects\Api\Data\RedirectRuleInterface;
use Panth\Redirects\Api\RedirectMatcherInterface;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Model\Redirect\PathNormalizer;
use Panth\Redirects\Service\RedirectGuard;
use Psr\Log\LoggerInterface;

class Predispatch implements ObserverInterface
{
    private const MAX_CHAIN_HOPS = 10;

    public function __construct(
        private readonly RedirectMatcherInterface $matcher,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly ActionFlag $actionFlag,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly RedirectGuard $redirectGuard,
        private readonly PathNormalizer $pathNormalizer
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled()) {
                return;
            }
            if (!$this->redirectGuard->isSafeToRedirect($this->request)) {
                return;
            }
            if (!$this->isFrontendRequest()) {
                return;
            }

            $storeId = (int) $this->storeManager->getStore()->getId();

            $routedPath  = $this->pathNormalizer->normalize((string) $this->request->getPathInfo());
            $rule        = $this->matcher->match($routedPath, $storeId);
            $matchedPath = $routedPath;

            if ($rule === null && $this->config->isMatchOriginalUriEnabled($storeId)) {
                $originalPath = $this->originalRequestPath();
                if ($originalPath !== '' && $originalPath !== $routedPath) {
                    $rule        = $this->matcher->match($originalPath, $storeId);
                    $matchedPath = $originalPath;
                }
            }

            if ($rule === null) {
                return;
            }

            if ($rule->getMatchType() === RedirectRuleInterface::MATCH_MAINTENANCE) {
                if (method_exists($this->response, 'setStatusHeader')) {
                    $this->response->setStatusHeader(503, null, 'Service Unavailable');
                }
                if (method_exists($this->response, 'setHeader')) {
                    $this->response->setHeader('Retry-After', '3600', true);
                    $this->response->setHeader('Content-Type', 'text/plain; charset=UTF-8', true);
                }
                if (method_exists($this->response, 'setBody')) {
                    $this->response->setBody((string) $rule->getTarget());
                }
                $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
                $maintenanceId = $rule->getRedirectId();
                if ($maintenanceId !== null) {
                    $this->matcher->recordHit($maintenanceId);
                }
                return;
            }

            $target = (string) $rule->getTarget();
            if ($target === '') {
                return;
            }
            if (@preg_match('#^(javascript|data|vbscript):#i', $target)) {
                $this->logger->warning('[PanthRedirects] redirect blocked: dangerous URI scheme in target', [
                    'target' => mb_substr($target, 0, 200),
                ]);
                return;
            }
            if (@preg_match('#^https?://#i', $target)) {
                $targetHost = (string) parse_url($target, PHP_URL_HOST);
                if ($targetHost !== '' && !$this->isAllowedHost($targetHost)) {
                    $this->logger->warning('[PanthRedirects] redirect blocked: external host not in store URLs', [
                        'target_host' => $targetHost,
                    ]);
                    return;
                }
            } elseif ($target[0] !== '/') {
                $target = '/' . $target;
            }
            $status = $rule->getStatusCode() ?: 301;
            if ($status >= 300 && $status < 400) {
                if (!$this->isSafeRedirectTarget($target)) {
                    $this->logger->warning('[PanthRedirects] redirect blocked: unsafe target', [
                        'target' => mb_substr($target, 0, 200),
                    ]);
                    return;
                }
                if ($this->isSelfRedirect($matchedPath, $target)) {
                    return;
                }
                if ($this->leadsToLoop($matchedPath, $target, $storeId)) {
                    $this->logger->warning('[PanthRedirects] redirect blocked: redirect loop or chain too long', [
                        'path'   => mb_substr($matchedPath, 0, 200),
                        'target' => mb_substr($target, 0, 200),
                    ]);
                    return;
                }
                if (method_exists($this->response, 'setRedirect')) {
                    $this->response->setRedirect($this->withQueryString($target), $status);
                }
            } else {
                if (method_exists($this->response, 'setStatusHeader')) {
                    $this->response->setStatusHeader($status, null, $this->reasonPhrase($status));
                }
                if (method_exists($this->response, 'setHeader')) {
                    $this->response->setHeader('Content-Type', 'text/plain; charset=UTF-8', true);
                }
                if (method_exists($this->response, 'setBody')) {
                    $this->response->setBody((string) $rule->getTarget());
                }
                if ($status === 503 && method_exists($this->response, 'setHeader')) {
                    $this->response->setHeader('Retry-After', '3600', true);
                }
            }
            $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);

            $id = $rule->getRedirectId();
            if ($id !== null) {
                $this->matcher->recordHit($id);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthRedirects] redirect predispatch failed: ' . $e->getMessage());
        }
    }

    private function originalRequestPath(): string
    {
        $uri = (string) $this->request->getRequestUri();
        if ($uri === '') {
            return '';
        }

        $basePath = method_exists($this->request, 'getBasePath')
            ? rtrim((string) $this->request->getBasePath(), '/')
            : '';
        if ($basePath !== '' && $basePath !== '/' && strpos($uri, $basePath) === 0) {
            $uri = substr($uri, strlen($basePath));
        }

        return $this->pathNormalizer->normalize($uri);
    }

    private function leadsToLoop(string $requestPath, string $target, int $storeId): bool
    {
        $visited = [$this->pathNormalizer->normalize($requestPath) => true];
        $next = $target;
        for ($hop = 0; $hop < self::MAX_CHAIN_HOPS; $hop++) {
            if (!$this->isLocalTarget($next)) {
                return false;
            }
            $path = $this->pathNormalizer->normalize($next);
            if (isset($visited[$path])) {
                return true;
            }
            $visited[$path] = true;
            $rule = $this->matcher->match($path, $storeId);
            if ($rule === null || $rule->getMatchType() === RedirectRuleInterface::MATCH_MAINTENANCE) {
                return false;
            }
            $status = $rule->getStatusCode() ?: 301;
            if ($status < 300 || $status >= 400) {
                return false;
            }
            $next = (string) $rule->getTarget();
            if ($next === '') {
                return false;
            }
        }
        return true;
    }

    private function isLocalTarget(string $target): bool
    {
        if (preg_match('#^https?://#i', $target) !== 1) {
            return true;
        }
        try {
            $currentHost = (string) parse_url((string) $this->storeManager->getStore()->getBaseUrl(), PHP_URL_HOST);
        } catch (\Throwable) {
            return false;
        }
        $targetHost = (string) parse_url($target, PHP_URL_HOST);
        return $currentHost !== '' && strcasecmp($currentHost, $targetHost) === 0;
    }

    private function withQueryString(string $target): string
    {
        if (str_contains($target, '?') || str_contains($target, '#')) {
            return $target;
        }
        $query = '';
        if (method_exists($this->request, 'getServer')) {
            $query = (string) $this->request->getServer('QUERY_STRING');
        }
        if ($query === '') {
            $uri = (string) $this->request->getRequestUri();
            $position = strpos($uri, '?');
            $query = $position === false ? '' : substr($uri, $position + 1);
        }
        if ($query === '' || preg_match('/[\x00-\x1F\x7F]/', $query) === 1) {
            return $target;
        }
        return $target . '?' . $query;
    }

    private function isSelfRedirect(string $requestPath, string $target): bool
    {
        return $this->pathNormalizer->normalize($target) === $this->pathNormalizer->normalize($requestPath);
    }

    private function isAllowedHost(string $host): bool
    {
        try {
            foreach ($this->storeManager->getStores() as $store) {
                $baseUrl = (string) $store->getBaseUrl();
                $storeHost = (string) parse_url($baseUrl, PHP_URL_HOST);
                if ($storeHost !== '' && strcasecmp($host, $storeHost) === 0) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    private function isSafeRedirectTarget(string $target): bool
    {
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $target) === 1) {
            return false;
        }
        return !str_starts_with($target, '//');
    }

    private function isFrontendRequest(): bool
    {
        $areaFront = method_exists($this->request, 'getFrontName') ? (string) $this->request->getFrontName() : '';
        if ($areaFront === 'admin' || ($areaFront === '' && strpos((string) $this->request->getPathInfo(), '/admin') === 0)) {
            return false;
        }
        return true;
    }

    private function reasonPhrase(int $status): string
    {
        return match ($status) {
            410 => 'Gone',
            451 => 'Unavailable For Legal Reasons',
            503 => 'Service Unavailable',
            default => '',
        };
    }
}
