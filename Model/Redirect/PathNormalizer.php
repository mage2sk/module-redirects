<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

class PathNormalizer
{
    public function normalize(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        $hash = strpos($path, '#');
        if ($hash !== false) {
            $path = substr($path, 0, $hash);
        }

        $query = strpos($path, '?');
        if ($query !== false) {
            $path = substr($path, 0, $query);
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?? '');
        }

        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }
}
