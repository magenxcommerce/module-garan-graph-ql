<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model;

use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Language\LanguageRegistry;
use Magenx\GaranGraphQl\Model\Source\BrandSource;
use Magenx\GaranGraphQl\Model\Source\EmailMode;
use Magenx\GaranGraphQl\Model\Source\ModelIdentifierSource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @var array<string, string|null>
     */
    private array $values = [];

    private Config $config;

    protected function setUp(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path): ?string => $this->values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn (string $path): bool => ($this->values[$path] ?? '0') === '1'
        );

        $this->config = new Config($scopeConfig, new LanguageRegistry());
    }

    public function testOnlyInlineShowsTheGraphicInTheEmail(): void
    {
        $this->values = [
            Config::XML_PATH_ENABLED => '1',
            Config::XML_PATH_GARAN_ENABLED => '1',
            Config::XML_PATH_EMAIL_NOTICE => EmailMode::INLINE,
            Config::XML_PATH_EMAIL_GARAN => EmailMode::INLINE,
        ];

        self::assertTrue($this->config->isNoticeEmailInline(1));
        self::assertTrue($this->config->isGaranEmailInline(1));

        foreach ([EmailMode::ATTACHMENT, EmailMode::NO, 'direct', ''] as $mode) {
            $this->values[Config::XML_PATH_EMAIL_NOTICE] = $mode;
            $this->values[Config::XML_PATH_EMAIL_GARAN] = $mode;

            self::assertFalse($this->config->isNoticeEmailInline(1), $mode);
            self::assertFalse($this->config->isGaranEmailInline(1), $mode);
        }
    }

    public function testGaranRequiresModuleAndGaranEnabled(): void
    {
        $this->values = [
            Config::XML_PATH_ENABLED => '1',
            Config::XML_PATH_GARAN_ENABLED => '0',
            Config::XML_PATH_EMAIL_GARAN => EmailMode::INLINE,
        ];
        self::assertFalse($this->config->isGaranActive(1));
        self::assertFalse($this->config->isGaranEmailInline(1));

        $this->values[Config::XML_PATH_GARAN_ENABLED] = '1';
        self::assertTrue($this->config->isGaranActive(1));
        self::assertTrue($this->config->isGaranEmailInline(1));

        $this->values[Config::XML_PATH_ENABLED] = '0';
        self::assertFalse($this->config->isGaranActive(1));
        self::assertFalse($this->config->isNoticeEmailInline(1));
    }

    public function testConfiguredLanguageWinsOverLocale(): void
    {
        $this->values = [
            Config::XML_PATH_LANGUAGE => 'en',
            'general/locale/code' => 'de_AT',
        ];

        self::assertSame('en', $this->config->getLanguageCode(21));
    }

    public function testLanguageFallsBackToStoreLocale(): void
    {
        $this->values = [
            Config::XML_PATH_LANGUAGE => '',
            'general/locale/code' => 'de_AT',
        ];

        self::assertSame('de', $this->config->getLanguageCode(1));
    }

    public function testLanguageFallsBackToEnglishForNonEuLocaleOrInvalidConfig(): void
    {
        $this->values = [
            Config::XML_PATH_LANGUAGE => 'xx',
            'general/locale/code' => 'tr_TR',
        ];

        self::assertSame(LanguageRegistry::FALLBACK_LANGUAGE, $this->config->getLanguageCode(1));
    }

    public function testExcludedProductTypesAreTrimmedAndEmptyEntriesRemoved(): void
    {
        $this->values = [
            Config::XML_PATH_EXCLUDED_PRODUCT_TYPES => ' virtual, ,downloadable,mageworx_giftcards ',
        ];

        self::assertSame(
            ['virtual', 'downloadable', 'mageworx_giftcards'],
            $this->config->getExcludedProductTypes(1)
        );
    }

    public function testExcludedProductTypesEmptyWhenNotConfigured(): void
    {
        self::assertSame([], $this->config->getExcludedProductTypes(1));
    }

    public function testBrandSourceFallsBackToTheGaranAttribute(): void
    {
        self::assertSame(BrandSource::GARAN_ATTRIBUTE, $this->config->getBrandSource(1));

        $this->values[Config::XML_PATH_GARAN_BRAND_SOURCE] = 'something_else';

        self::assertSame(BrandSource::GARAN_ATTRIBUTE, $this->config->getBrandSource(1));
    }

    public function testBrandAttributeAndValueOnlyAnswerForTheirOwnSource(): void
    {
        $this->values = [
            Config::XML_PATH_GARAN_BRAND_SOURCE => BrandSource::PRODUCT_ATTRIBUTE,
            Config::XML_PATH_GARAN_BRAND_ATTRIBUTE => 'manufacturer',
            Config::XML_PATH_GARAN_BRAND_VALUE => 'Fixed Brand',
        ];

        self::assertSame('manufacturer', $this->config->getBrandAttribute(1));
        self::assertSame('', $this->config->getBrandValue(1));

        $this->values[Config::XML_PATH_GARAN_BRAND_SOURCE] = BrandSource::CONFIG_VALUE;

        self::assertSame('', $this->config->getBrandAttribute(1));
        self::assertSame('Fixed Brand', $this->config->getBrandValue(1));
    }

    public function testModelIdentifierSourceFallsBackToTheGaranAttribute(): void
    {
        self::assertSame(ModelIdentifierSource::GARAN_ATTRIBUTE, $this->config->getModelIdentifierSource(1));

        $this->values[Config::XML_PATH_GARAN_MODEL_SOURCE] = ModelIdentifierSource::PRODUCT_NAME;

        self::assertSame(ModelIdentifierSource::PRODUCT_NAME, $this->config->getModelIdentifierSource(1));
    }

    public function testGaranTermsUrlIsEmptyWhenUnset(): void
    {
        self::assertSame('', $this->config->getGaranTermsUrl(1));

        $this->values[Config::XML_PATH_GARAN_TERMS_URL] = 'https://example.com/terms';

        self::assertSame('https://example.com/terms', $this->config->getGaranTermsUrl(1));
    }

    public function testEmailAttachmentsFollowTheirKillSwitch(): void
    {
        $this->values = [
            Config::XML_PATH_ENABLED => '1',
            Config::XML_PATH_EMAIL_NOTICE => EmailMode::ATTACHMENT,
            Config::XML_PATH_GARAN_ENABLED => '1',
            Config::XML_PATH_EMAIL_GARAN => EmailMode::ATTACHMENT,
        ];

        self::assertTrue($this->config->isNoticeEmailAttachmentEnabled(1));
        self::assertTrue($this->config->isGaranEmailAttachmentEnabled(1));

        $this->values[Config::XML_PATH_ENABLED] = '0';

        self::assertFalse($this->config->isNoticeEmailAttachmentEnabled(1));
        self::assertFalse($this->config->isGaranEmailAttachmentEnabled(1));
    }

    public function testInlineAndNoDoNotAttachAnything(): void
    {
        $this->values = [Config::XML_PATH_ENABLED => '1', Config::XML_PATH_GARAN_ENABLED => '1'];

        foreach ([EmailMode::INLINE, EmailMode::NO, ''] as $mode) {
            $this->values[Config::XML_PATH_EMAIL_NOTICE] = $mode;
            $this->values[Config::XML_PATH_EMAIL_GARAN] = $mode;

            self::assertFalse($this->config->isNoticeEmailAttachmentEnabled(1), $mode);
            self::assertFalse($this->config->isGaranEmailAttachmentEnabled(1), $mode);
        }
    }
}
