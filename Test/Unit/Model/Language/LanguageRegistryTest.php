<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Language;

use Magenx\GaranGraphQl\Model\Language\LanguageRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LanguageRegistryTest extends TestCase
{
    private LanguageRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new LanguageRegistry();
    }

    public function testContainsExactlyTheTwentyFourOfficialEuLanguages(): void
    {
        $expected = [
            'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'ga', 'hr',
            'hu', 'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
        ];

        self::assertSame($expected, $this->registry->getCodes());
    }

    /**
     * @dataProvider yourEuropeProvider
     */
    public function testYourEuropeLinkMatchesQrCodeTarget(string $code, string $displayUrl, string $url): void
    {
        self::assertSame($displayUrl, $this->registry->getYourEuropeDisplayUrl($code));
        self::assertSame($url, $this->registry->getYourEuropeUrl($code));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function yourEuropeProvider(): array
    {
        return [
            'German' => ['de', 'europa.eu/youreurope/garantien', 'https://europa.eu/youreurope/garantien'],
            'English' => ['en', 'europa.eu/youreurope/guarantees', 'https://europa.eu/youreurope/guarantees'],
            'Czech, non-ASCII' => [
                'cs',
                'europa.eu/youreurope/záruky_cs',
                'https://europa.eu/youreurope/z%C3%A1ruky_cs',
            ],
            'Swedish' => [
                'sv',
                'europa.eu/youreurope/reklamationsrätt',
                'https://europa.eu/youreurope/reklamationsr%C3%A4tt',
            ],
        ];
    }

    public function testNoticeAssetIdUsesLanguageAndExtension(): void
    {
        self::assertSame('Magenx_GaranGraphQl::notice/de.svg', $this->registry->getNoticeAssetId('de', 'svg'));
        self::assertSame('Magenx_GaranGraphQl::notice/en.png', $this->registry->getNoticeAssetId('en', 'png'));
    }

    public function testNoticeAssetIdRejectsOtherExtensions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry->getNoticeAssetId('de', 'jpg');
    }

    /**
     * @dataProvider localeProvider
     */
    public function testFromLocale(string $locale, ?string $expected): void
    {
        self::assertSame($expected, $this->registry->fromLocale($locale));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function localeProvider(): array
    {
        return [
            'Austrian German' => ['de_AT', 'de'],
            'British English' => ['en_GB', 'en'],
            'Swiss French' => ['fr_CH', 'fr'],
            'Hyphenated' => ['nl-BE', 'nl'],
            'Non-EU language' => ['tr_TR', null],
            'Empty' => ['', null],
        ];
    }

    public function testUnsupportedLanguageThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry->getYourEuropeUrl('tr');
    }

    public function testNameIsReturnedForSupportedLanguage(): void
    {
        self::assertSame('German', $this->registry->getName('de'));
        self::assertTrue($this->registry->isSupported('ga'));
        self::assertFalse($this->registry->isSupported('DE'));
    }
}
