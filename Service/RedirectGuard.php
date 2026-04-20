<?php
declare(strict_types=1);

namespace Panth\Redirects\Service;

use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Framework\App\Area;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;

/**
 * Centralised safety checks for any frontend redirect (trailing-slash,
 * lowercase, homepage, custom rules, etc.).
 *
 * A misfired 301/302 can be catastrophic: on non-GET requests browsers silently
 * convert the method to GET, which turns every AJAX/API POST into a broken GET
 * and 404s it. On XHR requests, a 301 can cause fetch() to follow a redirect
 * the caller never expected.
 *
 * Every redirect path MUST call `isSafeToRedirect()` before issuing a
 * redirect — this is the ONE source of truth.
 */
class RedirectGuard
{
    /**
     * URL path prefixes that should NEVER be redirected, because they are API
     * endpoints, asset paths, or handle their own routing.
     */
    private const SKIP_PREFIXES = [
        '/rest/',
        '/soap/',
        '/graphql',
        '/V1/',
        '/static/',
        '/media/',
        '/pub/',
        '/errors/',
        '/health_check',
        '/sitemap',
        '/robots.txt',
        '/favicon.ico',
    ];

    public function __construct(
        private readonly State $appState,
        private readonly BackendHelper $backendHelper
    ) {
    }

    public function isSafeToRedirect(RequestInterface $request): bool
    {
        if (strtoupper((string) $request->getMethod()) !== 'GET') {
            return false;
        }

        if ($this->isAjax($request)) {
            return false;
        }

        if ($this->isAdmin($request)) {
            return false;
        }

        try {
            if ($this->appState->getAreaCode() !== Area::AREA_FRONTEND) {
                return false;
            }
        } catch (\Throwable) {
            // Area not set yet — fall through.
        }

        $uri = (string) $request->getRequestUri();
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (stripos($uri, $prefix) === 0) {
                return false;
            }
        }

        return true;
    }

    private function isAjax(RequestInterface $request): bool
    {
        $xhrHeader = $this->readHeader($request, 'X-Requested-With', 'HTTP_X_REQUESTED_WITH');
        if (strcasecmp($xhrHeader, 'XMLHttpRequest') === 0) {
            return true;
        }

        $contentType = $this->readHeader($request, 'Content-Type', 'CONTENT_TYPE');
        if ($contentType !== '' && stripos($contentType, 'application/json') !== false) {
            return true;
        }

        $secFetchMode = $this->readHeader($request, 'Sec-Fetch-Mode', 'HTTP_SEC_FETCH_MODE');
        if ($secFetchMode !== '' && strcasecmp($secFetchMode, 'navigate') !== 0) {
            return true;
        }

        if (method_exists($request, 'isAjax')) {
            try {
                if ($request->isAjax()) {
                    return true;
                }
            } catch (\Throwable) {
                // Ignore.
            }
        }

        return false;
    }

    private function readHeader(RequestInterface $request, string $headerName, string $serverKey): string
    {
        $value = (string) ($_SERVER[$serverKey] ?? '');
        if ($value !== '') {
            return $value;
        }

        if (method_exists($request, 'getServer')) {
            try {
                $value = (string) $request->getServer($serverKey, '');
                if ($value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
                // Ignore.
            }
        }

        if (method_exists($request, 'getServerValue')) {
            try {
                $value = (string) $request->getServerValue($serverKey, '');
                if ($value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
                // Ignore.
            }
        }

        if (method_exists($request, 'getHeader')) {
            try {
                $value = (string) $request->getHeader($headerName);
                if ($value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
                // Ignore.
            }
        }

        return '';
    }

    private function isAdmin(RequestInterface $request): bool
    {
        $uri = (string) $request->getRequestUri();
        try {
            $adminFront = (string) $this->backendHelper->getAreaFrontName();
            if ($adminFront !== '' && (
                stripos($uri, '/' . $adminFront . '/') === 0
                || stripos($uri, '/' . $adminFront) === 0
            )) {
                return true;
            }
        } catch (\Throwable) {
            // Fall through.
        }
        if (stripos($uri, '/admin') === 0) {
            return true;
        }
        return false;
    }
}
