<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Panth\Redirects\Api\Data\RedirectRuleInterface;
use Panth\Redirects\Model\Config\Source\StatusCode;
use Psr\Log\LoggerInterface;

class ImportExport
{
    public const HEADER = [
        'store_id',
        'match_type',
        'pattern',
        'target',
        'status_code',
        'priority',
        'is_active',
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Loop $loopDetector,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache
    ) {
    }

    public function import(string $filePath, bool $dryRun = false): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new LocalizedException(__('CSV file not found or not readable: %1', $filePath));
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new LocalizedException(__('Cannot open CSV file: %1', $filePath));
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $rows     = [];

        try {
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            if ($header === false) {
                throw new LocalizedException(__('CSV file is empty.'));
            }
            $header = array_map(static fn($h) => strtolower(trim((string) $h)), $header);
            $missing = array_diff(self::HEADER, $header);
            if (!empty($missing)) {
                throw new LocalizedException(__('Missing CSV columns: %1', implode(',', $missing)));
            }

            $lineNo = 1;
            while (($raw = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $lineNo++;
                if ($raw === [null] || $raw === false) {
                    continue;
                }

                $row = array_combine($header, array_pad($raw, count($header), ''));
                if (!is_array($row)) {
                    $errors[] = "Line {$lineNo}: malformed";
                    $skipped++;
                    continue;
                }
                $validation = $this->validateRow($row);
                if ($validation !== null) {
                    $errors[] = "Line {$lineNo}: {$validation}";
                    $skipped++;
                    continue;
                }

                $storeId = (int) $row['store_id'];
                if ($row['match_type'] === RedirectRuleInterface::MATCH_LITERAL) {
                    $loop = $this->loopDetector->detect(
                        (string) $row['pattern'],
                        (string) $row['target'],
                        $storeId
                    );
                    if (!empty($loop)) {
                        $errors[] = "Line {$lineNo}: loop detected (" . implode(' -> ', $loop) . ')';
                        $skipped++;
                        continue;
                    }
                }

                $rows[] = $row;
                if (!$dryRun) {
                    $this->persist($row);
                }
                $imported++;
            }
        } finally {
            fclose($handle);
            if (!$dryRun && $imported > 0) {
                $this->cache->clean([Matcher::CACHE_TAG]);
            }
        }

        return [
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'rows'     => $rows,
        ];
    }

    public function exportToStream($stream, ?array $storeIds = null): int
    {
        if (!is_resource($stream)) {
            throw new LocalizedException(__('Invalid export stream.'));
        }
        fputcsv($stream, self::HEADER, ',', '"', '\\');

        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_redirect');
        $select = $conn->select()->from($table, self::HEADER)->order('redirect_id ASC');
        if ($storeIds !== null && !empty($storeIds)) {
            $select->where('store_id IN (?)', $storeIds);
        }

        $count = 0;
        $stmt = $conn->query($select);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            fputcsv($stream, [
                (int) $row['store_id'],
                (string) $row['match_type'],
                $this->escapeCsvFormula((string) $row['pattern']),
                $this->escapeCsvFormula((string) $row['target']),
                (int) $row['status_code'],
                (int) $row['priority'],
                (int) $row['is_active'],
            ], ',', '"', '\\');
            $count++;
        }
        return $count;
    }

    private function validateRow(array $row): ?string
    {
        $matchType = (string) $row['match_type'];
        if (!in_array($matchType, [
            RedirectRuleInterface::MATCH_LITERAL,
            RedirectRuleInterface::MATCH_REGEX,
            RedirectRuleInterface::MATCH_MAINTENANCE,
        ], true)) {
            return 'invalid match_type';
        }
        $pattern = $this->sanitizeCsvValue(trim((string) $row['pattern']));
        $target  = $this->sanitizeCsvValue(trim((string) $row['target']));
        if ($pattern === '' || $target === '') {
            return 'empty pattern/target';
        }
        if (@preg_match('#^(javascript|data|vbscript):#i', $target)) {
            return 'dangerous URI scheme in target';
        }
        $status = (int) $row['status_code'];
        if (!in_array($status, StatusCode::ALLOWED, true)) {
            return "invalid status_code {$status}";
        }
        if ($matchType === RedirectRuleInterface::MATCH_REGEX) {
            $wrapped = '~' . str_replace('~', '\\~', $pattern) . '~';
            try {
                $testResult = @preg_match($wrapped, '');
            } catch (\Throwable) {
                $testResult = false;
            }
            if ($testResult === false) {
                return 'invalid regex';
            }
        }
        return null;
    }

    private function sanitizeCsvValue(string $value): string
    {
        if (preg_match('/^\'[=+\-@\t\r]/', $value) === 1) {
            $value = substr($value, 1);
        }
        return ltrim($value, "=+\-@\t\r");
    }

    private function escapeCsvFormula(string $value): string
    {
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'" . $value;
        }
        return $value;
    }

    private function persist(array $row): void
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_redirect');
        $data  = [
            'store_id'    => (int) $row['store_id'],
            'match_type'  => (string) $row['match_type'],
            'pattern'     => $this->sanitizeCsvValue((string) $row['pattern']),
            'target'      => $this->sanitizeCsvValue((string) $row['target']),
            'status_code' => (int) $row['status_code'],
            'priority'    => (int) ($row['priority'] ?? 10),
            'is_active'   => (int) ($row['is_active'] ?? 1),
        ];
        $existingId = $this->findExistingId($data['store_id'], $data['pattern']);
        if ($existingId !== null) {
            $data['is_auto_generated'] = 0;
            $conn->update($table, $data, ['redirect_id = ?' => $existingId]);
            return;
        }
        $conn->insert($table, $data);
    }

    private function findExistingId(int $storeId, string $pattern): ?int
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_redirect');
        $rows  = $conn->fetchPairs(
            $conn->select()
                ->from($table, ['redirect_id', 'pattern'])
                ->where('store_id = ?', $storeId)
                ->where('pattern = ?', $pattern)
                ->order('redirect_id ASC')
        );
        foreach ($rows as $id => $existingPattern) {
            if ((string) $existingPattern === $pattern) {
                return (int) $id;
            }
        }
        return null;
    }
}
