<?php
declare(strict_types=1);

namespace Panth\Redirects\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed config accessor for every `panth_redirects/*` admin field.
 *
 * Keeping all config paths behind this class means any future rename can
 * be done in one place and the rest of the module stays source-stable.
 */
class Config
{
    public const XML_GENERAL_ENABLED             = 'panth_redirects/general/enabled';
    public const XML_AUTO_REDIRECT_ENABLED       = 'panth_redirects/general/auto_redirect_enabled';
    public const XML_AUTO_REDIRECT_STRATEGY      = 'panth_redirects/general/redirect_target_strategy';
    public const XML_AUTO_REDIRECT_CUSTOM_URL    = 'panth_redirects/general/redirect_custom_url';
    public const XML_LOWERCASE_REDIRECT          = 'panth_redirects/general/lowercase_redirect';
    public const XML_HOMEPAGE_REDIRECT           = 'panth_redirects/general/homepage_redirect';
    public const XML_REMOVE_TRAILING_SLASH       = 'panth_redirects/general/remove_trailing_slash';
    public const XML_EXPIRY_DAYS                 = 'panth_redirects/general/expiry_days';

    public const XML_LOG_404                     = 'panth_redirects/logging/log_404';
    public const XML_LOG_404_RATE_LIMIT          = 'panth_redirects/logging/rate_limit_per_second';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_GENERAL_ENABLED, $storeId);
    }

    public function isAutoRedirectEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_AUTO_REDIRECT_ENABLED, $storeId);
    }

    public function getAutoRedirectTargetStrategy(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_AUTO_REDIRECT_STRATEGY, $storeId) ?? 'parent_category');
    }

    public function getAutoRedirectCustomUrl(?int $storeId = null): string
    {
        return (string) ($this->value(self::XML_AUTO_REDIRECT_CUSTOM_URL, $storeId) ?? '');
    }

    public function isLowercaseRedirectEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_LOWERCASE_REDIRECT, $storeId);
    }

    public function isHomepageRedirectEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_HOMEPAGE_REDIRECT, $storeId);
    }

    public function canonicalRemoveTrailingSlash(?int $storeId = null): bool
    {
        return $this->flag(self::XML_REMOVE_TRAILING_SLASH, $storeId);
    }

    public function getExpiryDays(?int $storeId = null): int
    {
        $value = $this->value(self::XML_EXPIRY_DAYS, $storeId);
        $days  = $value !== null ? (int) $value : 365;
        return $days > 0 ? $days : 365;
    }

    public function isLog404Enabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_LOG_404, $storeId);
    }

    public function getLog404RateLimit(?int $storeId = null): int
    {
        $value = $this->value(self::XML_LOG_404_RATE_LIMIT, $storeId);
        $limit = $value !== null ? (int) $value : 10;
        return $limit > 0 ? $limit : 10;
    }

    /**
     * Raw value accessor for paths this helper does not yet wrap in a typed
     * method. Keeps third-party callers honest — they still go through the
     * helper instead of reaching into ScopeConfigInterface directly.
     */
    public function getValue(string $path, ?int $storeId = null): mixed
    {
        return $this->value($path, $storeId);
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function value(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
