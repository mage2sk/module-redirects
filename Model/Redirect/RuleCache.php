<?php
declare(strict_types=1);

namespace Panth\Redirects\Model\Redirect;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use Psr\Log\LoggerInterface;

class RuleCache implements CacheInterface
{
    public const PAGE_CACHE_EVENT = 'clean_cache_by_tags';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ManagerInterface $eventManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getFrontend()
    {
        return $this->cache->getFrontend();
    }

    public function load($identifier)
    {
        return $this->cache->load($identifier);
    }

    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        return $this->cache->save($data, $identifier, $tags, $lifeTime);
    }

    public function remove($identifier)
    {
        return $this->cache->remove($identifier);
    }

    public function clean($tags = [])
    {
        $result = $this->cache->clean($tags);
        if (in_array(Matcher::CACHE_TAG, (array) $tags, true)) {
            try {
                $this->eventManager->dispatch(self::PAGE_CACHE_EVENT, ['object' => new PageCacheIdentity()]);
            } catch (\Throwable $e) {
                $this->logger->warning('[PanthRedirects] page cache clean failed: ' . $e->getMessage());
            }
        }
        return $result;
    }
}
