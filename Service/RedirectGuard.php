<?php
declare(strict_types=1);

namespace Panth\Redirects\Service;

use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Framework\App\Area;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;

class RedirectGuard
{
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
            }
        }

        if (method_exists($request, 'getServerValue')) {
            try {
                $value = (string) $request->getServerValue($serverKey, '');
                if ($value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
            }
        }

        if (method_exists($request, 'getHeader')) {
            try {
                $value = (string) $request->getHeader($headerName);
                if ($value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
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
        }
        if (stripos($uri, '/admin') === 0) {
            return true;
        }
        return false;
    }
}
