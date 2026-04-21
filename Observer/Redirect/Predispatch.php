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
use Panth\Redirects\Service\RedirectGuard;
use Psr\Log\LoggerInterface;

/**
 * controller_action_predispatch observer. Runs the Matcher; on a hit,
 * issues the redirect and stops action dispatch.
 */
class Predispatch implements ObserverInterface
{
    public function __construct(
        private readonly RedirectMatcherInterface $matcher,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly ActionFlag $actionFlag,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly RedirectGuard $redirectGuard
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
            $path    = (string) $this->request->getPathInfo();

            $rule = $this->matcher->match($path, $storeId);
            if ($rule === null) {
                return;
            }

            if ($rule->getMatchType() === RedirectRuleInterface::MATCH_MAINTENANCE) {
                if (method_exists($this->response, 'setStatusHeader')) {
                    $this->response->setStatusHeader(503, null, 'Service Unavailable');
                }
                if (method_exists($this->response, 'setHeader')) {
                    $this->response->setHeader('Retry-After', '3600', true);
                }
                if (method_exists($this->response, 'setBody')) {
                    $this->response->setBody((string) $rule->getTarget());
                }
                $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
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
                if (method_exists($this->response, 'setRedirect')) {
                    $this->response->setRedirect($target, $status);
                }
            } else {
                // 4xx/5xx (410, 451, 503): no Location header — emit status
                // and a short body so the client doesn't follow to `target`.
                if (method_exists($this->response, 'setStatusHeader')) {
                    $this->response->setStatusHeader($status, null, $this->reasonPhrase($status));
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
            return true;
        }
        return false;
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
