<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

class RegexPrefilter
{
    public const MODE_NONE = 'none';
    public const MODE_PREFIX = 'prefix';
    public const MODE_CONTAINS = 'contains';

    private const SINGLE_ESCAPES = 'dDwWsSbBAzZhHvVRNXGK';

    public function analyze(string $compiled): array
    {
        $none = ['mode' => self::MODE_NONE, 'needle' => '', 'ci' => false];
        if (strlen($compiled) < 2) {
            return $none;
        }
        $delimiter = $compiled[0];
        $end = strrpos($compiled, $delimiter);
        if ($end === false || $end === 0) {
            return $none;
        }
        $body  = substr($compiled, 1, $end - 1);
        $flags = substr($compiled, $end + 1);
        if (strpbrk($flags, 'xXJ') !== false) {
            return $none;
        }
        $ci = str_contains($flags, 'i');

        $atoms = $this->tokenize($body);
        if ($atoms === null) {
            return $none;
        }

        $anchored = $body !== '' && $body[0] === '^' && !str_contains($flags, 'm');
        $start = $anchored ? 1 : 0;

        $runs = [];
        $current = '';
        $currentStart = null;
        $count = count($atoms);
        for ($i = $start; $i < $count; $i++) {
            $atom = $atoms[$i];
            if ($atom === null) {
                if ($current !== '') {
                    $runs[] = [$currentStart, $current];
                }
                $current = '';
                $currentStart = null;
                continue;
            }
            if ($currentStart === null) {
                $currentStart = $i;
            }
            $current .= $atom;
        }
        if ($current !== '') {
            $runs[] = [$currentStart, $current];
        }
        if ($runs === []) {
            return $none;
        }

        if ($anchored && $runs[0][0] === $start) {
            $needle = $runs[0][1];
            $mode = self::MODE_PREFIX;
        } else {
            $needle = '';
            foreach ($runs as $run) {
                if (strlen($run[1]) > strlen($needle)) {
                    $needle = $run[1];
                }
            }
            $mode = self::MODE_CONTAINS;
        }

        return ['mode' => $mode, 'needle' => $needle, 'ci' => $ci];
    }

    public function accepts(array $filter, string $subject): bool
    {
        $mode   = (string) ($filter['mode'] ?? self::MODE_NONE);
        $needle = (string) ($filter['needle'] ?? '');
        if ($needle === '' || $mode === self::MODE_NONE) {
            return true;
        }
        $ci = !empty($filter['ci']);
        if ($mode === self::MODE_PREFIX) {
            return $ci
                ? strncasecmp($subject, $needle, strlen($needle)) === 0
                : str_starts_with($subject, $needle);
        }
        return $ci ? stripos($subject, $needle) !== false : str_contains($subject, $needle);
    }

    private function tokenize(string $body): ?array
    {
        if ($body !== '' && !ctype_print($body)) {
            return null;
        }
        $atoms = [];
        $len = strlen($body);
        $i = 0;
        while ($i < $len) {
            $ch = $body[$i];
            if ($ch === '\\') {
                if ($i + 1 >= $len) {
                    return null;
                }
                $next = $body[$i + 1];
                if (ctype_alnum($next)) {
                    if (!str_contains(self::SINGLE_ESCAPES, $next)) {
                        return null;
                    }
                    $atoms[] = null;
                } else {
                    $atoms[] = $next;
                }
                $i += 2;
                continue;
            }
            if ($ch === '|') {
                return null;
            }
            if ($ch === '(') {
                if (substr($body, $i, 2) === '(?' && $i + 2 < $len && strpos(':=!<', $body[$i + 2]) === false) {
                    return null;
                }
                $close = $this->findGroupEnd($body, $i);
                if ($close === null) {
                    return null;
                }
                $atoms[] = null;
                $i = $close + 1;
                continue;
            }
            if ($ch === '[') {
                $close = $this->findClassEnd($body, $i);
                if ($close === null) {
                    return null;
                }
                $atoms[] = null;
                $i = $close + 1;
                continue;
            }
            if ($ch === '*' || $ch === '?') {
                $this->makeLastOptional($atoms);
                $i = $this->skipQuantifierSuffix($body, $i + 1);
                continue;
            }
            if ($ch === '+') {
                $atoms[] = null;
                $i = $this->skipQuantifierSuffix($body, $i + 1);
                continue;
            }
            if ($ch === '{') {
                if (preg_match('/\G\{\d*,?\d*\}/', $body, $m, 0, $i) === 1 && $m[0] !== '{}' && $m[0] !== '{,}') {
                    $this->makeLastOptional($atoms);
                    $i = $this->skipQuantifierSuffix($body, $i + strlen($m[0]));
                    continue;
                }
                $atoms[] = null;
                $i++;
                continue;
            }
            if ($ch === '.' || $ch === '^' || $ch === '$' || $ch === ')') {
                if ($ch === ')') {
                    return null;
                }
                $atoms[] = null;
                $i++;
                continue;
            }
            $atoms[] = $ch;
            $i++;
        }
        return $atoms;
    }

    private function makeLastOptional(array &$atoms): void
    {
        if ($atoms !== []) {
            $atoms[count($atoms) - 1] = null;
        }
    }

    private function skipQuantifierSuffix(string $body, int $i): int
    {
        if ($i < strlen($body) && ($body[$i] === '?' || $body[$i] === '+')) {
            return $i + 1;
        }
        return $i;
    }

    private function findClassEnd(string $body, int $open): ?int
    {
        $len = strlen($body);
        $i = $open + 1;
        if ($i < $len && $body[$i] === '^') {
            $i++;
        }
        if ($i < $len && $body[$i] === ']') {
            $i++;
        }
        while ($i < $len) {
            $ch = $body[$i];
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if ($ch === '[' && $i + 1 < $len && $body[$i + 1] === ':') {
                $posixEnd = strpos($body, ':]', $i + 2);
                if ($posixEnd !== false) {
                    $i = $posixEnd + 2;
                    continue;
                }
            }
            if ($ch === ']') {
                return $i;
            }
            $i++;
        }
        return null;
    }

    private function findGroupEnd(string $body, int $open): ?int
    {
        $len = strlen($body);
        $depth = 0;
        $i = $open;
        while ($i < $len) {
            $ch = $body[$i];
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if ($ch === '[') {
                $close = $this->findClassEnd($body, $i);
                if ($close === null) {
                    return null;
                }
                $i = $close + 1;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
            $i++;
        }
        return null;
    }
}
