<?php
declare(strict_types=1);

namespace Panth\Redirects\Observer\Redirect;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Redirects\Helper\Config;
use Panth\Redirects\Model\Redirect\AutoRedirectService;
use Psr\Log\LoggerInterface;

/**
 * Observer on `model_delete_before` — runs for every model, strict type
 * check inside. Creates a 301 redirect from every URL rewrite of the
 * product being deleted to its primary category (or homepage fallback).
 */
class ProductDeleteBefore implements ObserverInterface
{
    private const STRATEGY_HOMEPAGE   = 'homepage';
    private const STRATEGY_CUSTOM_URL = 'custom_url';

    public function __construct(
        private readonly AutoRedirectService $autoRedirectService,
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled() || !$this->config->isAutoRedirectEnabled()) {
                return;
            }

            $event = $observer->getEvent();
            $product = $event->getProduct() ?? $event->getObject() ?? $event->getEntity();
            if (!$product instanceof ProductInterface || !$product->getId()) {
                return;
            }

            $productId = (int) $product->getId();
            $rewrites = $this->getProductUrlRewrites($productId);

            if ($rewrites === []) {
                return;
            }

            $targetPath = $this->resolveTargetPath($product);

            foreach ($rewrites as $rewrite) {
                $storeId = (int) ($rewrite['store_id'] ?? 0);
                $sourcePath = (string) ($rewrite['request_path'] ?? '');
                if ($sourcePath === '') {
                    continue;
                }
                if (!$this->config->isEnabled($storeId) || !$this->config->isAutoRedirectEnabled($storeId)) {
                    continue;
                }
                $this->autoRedirectService->createRedirect($sourcePath, $targetPath, $storeId);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[PanthRedirects] ProductDeleteBefore observer failed',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * @return array<int, array{request_path: string, store_id: int}>
     */
    private function getProductUrlRewrites(int $productId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('url_rewrite');

        $select = $connection->select()
            ->from($table, ['request_path', 'store_id'])
            ->where('entity_type = ?', 'product')
            ->where('entity_id = ?', $productId);

        /** @var array<int, array{request_path: string, store_id: int}> $rows */
        $rows = $connection->fetchAll($select);
        return $rows;
    }

    private function resolveTargetPath(ProductInterface $product): string
    {
        $strategy = $this->config->getAutoRedirectTargetStrategy();

        if ($strategy === self::STRATEGY_HOMEPAGE) {
            return '/';
        }

        if ($strategy === self::STRATEGY_CUSTOM_URL) {
            $custom = $this->config->getAutoRedirectCustomUrl();
            $sanitised = $this->sanitiseCustomUrl($custom);
            return $sanitised !== '' ? $sanitised : '/';
        }

        $categoryUrl = $this->getPrimaryCategoryUrl($product);
        return $categoryUrl !== '' ? $categoryUrl : '/';
    }

    private function sanitiseCustomUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (@preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw) || str_starts_with($raw, '//')) {
            return '';
        }
        if (@preg_match('/[\x00-\x1F\x7F]/', $raw)) {
            return '';
        }
        $parts = explode('/', $raw);
        foreach ($parts as $part) {
            if ($part === '..') {
                return '';
            }
        }
        if ($raw[0] !== '/') {
            $raw = '/' . $raw;
        }
        return $raw;
    }

    private function getPrimaryCategoryUrl(ProductInterface $product): string
    {
        try {
            $categoryIds = $product->getCategoryIds();
            if (empty($categoryIds)) {
                return '';
            }

            $categoryId = (int) reset($categoryIds);
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('url_rewrite');

            $select = $connection->select()
                ->from($table, ['request_path'])
                ->where('entity_type = ?', 'category')
                ->where('entity_id = ?', $categoryId)
                ->where('metadata IS NULL')
                ->limit(1);

            $path = $connection->fetchOne($select);

            return $path !== false ? (string) $path : '';
        } catch (\Throwable $e) {
            $this->logger->debug(
                '[PanthRedirects] Could not resolve primary category URL',
                ['product_id' => $product->getId(), 'error' => $e->getMessage()]
            );
            return '';
        }
    }
}
