<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model;

use Magenx\GaranGraphQl\Model\Language\LanguageRegistry;
use Magenx\GaranGraphQl\Model\Source\BrandSource;
use Magenx\GaranGraphQl\Model\Source\EmailMode;
use Magenx\GaranGraphQl\Model\Source\ModelIdentifierSource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ENABLED = 'magenx_garan/general/enabled';
    public const XML_PATH_LANGUAGE = 'magenx_garan/general/language';
    public const XML_PATH_EXCLUDED_PRODUCT_TYPES = 'magenx_garan/general/excluded_product_types';
    public const XML_PATH_GARAN_ENABLED = 'magenx_garan/garan/enabled';
    public const XML_PATH_GARAN_BRAND_SOURCE = 'magenx_garan/garan/brand_source';
    public const XML_PATH_GARAN_BRAND_ATTRIBUTE = 'magenx_garan/garan/brand_attribute';
    public const XML_PATH_GARAN_BRAND_VALUE = 'magenx_garan/garan/brand_value';
    public const XML_PATH_GARAN_MODEL_SOURCE = 'magenx_garan/garan/model_source';
    public const XML_PATH_GARAN_TERMS_URL = 'magenx_garan/garan/terms_url';
    public const XML_PATH_EMAIL_NOTICE = 'magenx_garan/email/notice';
    public const XML_PATH_EMAIL_GARAN = 'magenx_garan/email/garan';
    public const XML_PATH_EMAIL_ATTACH_TERMS = 'magenx_garan/email/attach_terms';
    public const XML_PATH_EMAIL_TERMS_FILE = 'magenx_garan/email/terms_file';
    public const XML_PATH_EMAIL_TERMS_FILENAME = 'magenx_garan/email/terms_filename';

    /**
     * Media sub directory the guarantee terms file is uploaded to (system.xml upload_dir).
     */
    public const TERMS_UPLOAD_DIR = 'magenx_garan/terms';

    private const XML_PATH_LOCALE = 'general/locale/code';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LanguageRegistry $languageRegistry
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Module and GARAN label are both enabled for the store.
     */
    public function isGaranActive(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && $this->scopeConfig->isSetFlag(self::XML_PATH_GARAN_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Configured label language, or the language of the store locale, or English.
     */
    public function getLanguageCode(?int $storeId = null): string
    {
        $configured = $this->getString(self::XML_PATH_LANGUAGE, $storeId);
        if ($configured !== '' && $this->languageRegistry->isSupported($configured)) {
            return $configured;
        }

        return $this->languageRegistry->fromLocale($this->getString(self::XML_PATH_LOCALE, $storeId))
            ?? LanguageRegistry::FALLBACK_LANGUAGE;
    }

    /**
     * @return list<string>
     */
    public function getExcludedProductTypes(?int $storeId = null): array
    {
        $types = array_map('trim', explode(',', $this->getString(self::XML_PATH_EXCLUDED_PRODUCT_TYPES, $storeId)));

        return array_values(array_filter($types, static fn (string $type): bool => $type !== ''));
    }

    /**
     * Where the brand on the label comes from when the product carries none.
     */
    public function getBrandSource(?int $storeId = null): string
    {
        $source = $this->getString(self::XML_PATH_GARAN_BRAND_SOURCE, $storeId);

        return in_array($source, BrandSource::SOURCES, true) ? $source : BrandSource::GARAN_ATTRIBUTE;
    }

    /**
     * Product attribute the brand is read from, empty unless the source is "product_attribute".
     */
    public function getBrandAttribute(?int $storeId = null): string
    {
        return $this->getBrandSource($storeId) === BrandSource::PRODUCT_ATTRIBUTE
            ? $this->getString(self::XML_PATH_GARAN_BRAND_ATTRIBUTE, $storeId)
            : '';
    }

    /**
     * Fixed brand, empty unless the source is "config_value".
     */
    public function getBrandValue(?int $storeId = null): string
    {
        return $this->getBrandSource($storeId) === BrandSource::CONFIG_VALUE
            ? $this->getString(self::XML_PATH_GARAN_BRAND_VALUE, $storeId)
            : '';
    }

    /**
     * Where the model identifier comes from when the product carries none.
     */
    public function getModelIdentifierSource(?int $storeId = null): string
    {
        $source = $this->getString(self::XML_PATH_GARAN_MODEL_SOURCE, $storeId);

        return in_array($source, ModelIdentifierSource::SOURCES, true)
            ? $source
            : ModelIdentifierSource::GARAN_ATTRIBUTE;
    }

    /**
     * Guarantee terms URL for every product without its own.
     */
    public function getGaranTermsUrl(?int $storeId = null): string
    {
        return $this->getString(self::XML_PATH_GARAN_TERMS_URL, $storeId);
    }

    /**
     * Whether the notice graphic is shown in the body of the order confirmation.
     */
    public function isNoticeEmailInline(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && $this->getEmailMode(self::XML_PATH_EMAIL_NOTICE, $storeId) === EmailMode::INLINE;
    }

    /**
     * Whether the GARAN labels are shown in the body of the order confirmation, below the items.
     */
    public function isGaranEmailInline(?int $storeId = null): bool
    {
        return $this->isGaranActive($storeId)
            && $this->getEmailMode(self::XML_PATH_EMAIL_GARAN, $storeId) === EmailMode::INLINE;
    }

    /**
     * Whether the notice graphic travels with the order confirmation as a file instead of being shown inline.
     */
    public function isNoticeEmailAttachmentEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && $this->getEmailMode(self::XML_PATH_EMAIL_NOTICE, $storeId) === EmailMode::ATTACHMENT;
    }

    /**
     * Whether the GARAN label graphics travel with the order confirmation as files, one per labelled item.
     */
    public function isGaranEmailAttachmentEnabled(?int $storeId = null): bool
    {
        return $this->isGaranActive($storeId)
            && $this->getEmailMode(self::XML_PATH_EMAIL_GARAN, $storeId) === EmailMode::ATTACHMENT;
    }

    /**
     * The guarantee statement has to reach the consumer on a durable medium at the latest at delivery
     * (§ 9a (3) KSchG, § 479 (2) BGB); a link is not enough (CJEU C-49/11).
     */
    public function isTermsAttachmentEnabled(?int $storeId = null): bool
    {
        return $this->isGaranActive($storeId)
            && $this->scopeConfig->isSetFlag(
                self::XML_PATH_EMAIL_ATTACH_TERMS,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
    }

    /**
     * File name as stored by the config file backend, relative to the upload directory. Empty when unset.
     */
    public function getTermsFileValue(?int $storeId = null): string
    {
        return $this->getString(self::XML_PATH_EMAIL_TERMS_FILE, $storeId);
    }

    /**
     * File name shown in the email; empty string means "use the uploaded file name".
     */
    public function getTermsAttachmentFilename(?int $storeId = null): string
    {
        return $this->getString(self::XML_PATH_EMAIL_TERMS_FILENAME, $storeId);
    }

    private function getEmailMode(string $path, ?int $storeId): string
    {
        $mode = $this->getString($path, $storeId);

        return in_array($mode, EmailMode::MODES, true) ? $mode : EmailMode::NO;
    }

    private function getString(string $path, ?int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
    }
}
