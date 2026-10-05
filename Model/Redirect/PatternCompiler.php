<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

class PatternCompiler
{
    private const PROBE_LENGTH = 64;

    public function compile(string $pattern): ?string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return null;
        }

        if (preg_match('/^([~\/#!@%]).*\1[a-zA-Z]*$/s', $pattern) === 1) {
            $compiled = $pattern;
        } else {
            $compiled = '~' . str_replace('~', '\~', $pattern) . '~';
        }

        if (!$this->runsCleanly($compiled, '')) {
            return null;
        }
        return $compiled;
    }

    public function validate(string $pattern): ?string
    {
        $compiled = $this->compile($pattern);
        if ($compiled === null) {
            return 'Pattern is not a valid regular expression. Patterns that start and end with the same'
                . ' delimiter (/, #, ~, !, @, %) are read as delimited expressions with optional flags.';
        }
        foreach ($this->probes($pattern) as $probe) {
            if (!$this->runsCleanly($compiled, $probe)) {
                return 'Pattern is too expensive to evaluate (catastrophic backtracking). Simplify nested'
                    . ' or repeated quantifiers such as (a+)+.';
            }
        }
        return null;
    }

    public function expandTarget(string $target, array $groups): string
    {
        return (string) preg_replace_callback(
            '/\$\{(\d{1,2})\}|\$(\d{1,2})/',
            static function (array $m) use ($groups): string {
                $index = (int) ($m[1] !== '' ? $m[1] : ($m[2] ?? 0));
                return isset($groups[$index]) ? (string) $groups[$index] : '';
            },
            $target
        );
    }

    private function runsCleanly(string $compiled, string $subject): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            $result = preg_match($compiled, $subject);
        } catch (\Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
        return $result !== false && preg_last_error() === PREG_NO_ERROR;
    }

    private function probes(string $pattern): array
    {
        $body = ltrim(trim($pattern), '^');
        $prefix = '';
        if (preg_match('/^[^\\\[\](){}.*+?|$]*/', $body, $m) === 1) {
            $prefix = $m[0];
        }
        $units = ['a', '0', '/a', 'a-', 'a/', '-', '/'];
        if (preg_match_all('/[a-z0-9]/i', $body, $chars) > 0) {
            $units = array_merge($units, array_slice(array_values(array_unique($chars[0])), 0, 12));
        }
        $probes = [];
        foreach (array_unique($units) as $unit) {
            $repeated = str_repeat($unit, self::PROBE_LENGTH) . '!';
            $probes[] = '/' . $repeated;
            if ($prefix !== '') {
                $probes[] = $prefix . $repeated;
            }
        }
        return $probes;
    }
}
