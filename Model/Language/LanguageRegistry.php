<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Language;

use InvalidArgumentException;
use Magenx\GaranGraphQl\Model\Asset\MediaAssets;

/**
 * Official EU language versions of the harmonised notice and their Your Europe link targets
 * (EU Commission practical guidelines, Ares(2026)4331985, p. 15-16).
 */
class LanguageRegistry
{
    public const FALLBACK_LANGUAGE = 'en';
    public const YOUR_EUROPE_BASE = 'europa.eu/youreurope/';
    public const GARAN_INFO_URL = 'https://europa.eu/youreurope/commercial-guarantee-durability/index.htm';
    private const NOTICE_EXTENSIONS = ['svg', 'png'];

    /**
     * Language code => [English name, Your Europe path segment]
     */
    private const LANGUAGES = [
        'bg' => ['Bulgarian', 'гаранции'],
        'cs' => ['Czech', 'záruky_cs'],
        'da' => ['Danish', 'garantier'],
        'de' => ['German', 'garantien'],
        'el' => ['Greek', 'εγγυήσεις'],
        'en' => ['English', 'guarantees'],
        'es' => ['Spanish', 'garantías'],
        'et' => ['Estonian', 'garantiid'],
        'fi' => ['Finnish', 'virhevastuu'],
        'fr' => ['French', 'garanties'],
        'ga' => ['Irish', 'ráthaíochtaí'],
        'hr' => ['Croatian', 'jamstva_hr'],
        'hu' => ['Hungarian', 'jótállás'],
        'it' => ['Italian', 'garanzie'],
        'lt' => ['Lithuanian', 'garantijos'],
        'lv' => ['Latvian', 'garantijas'],
        'mt' => ['Maltese', 'garanziji'],
        'nl' => ['Dutch', 'garantie'],
        'pl' => ['Polish', 'gwarancje'],
        'pt' => ['Portuguese', 'garantias'],
        'ro' => ['Romanian', 'garanții'],
        'sk' => ['Slovak', 'záruky_sk'],
        'sl' => ['Slovenian', 'jamstva_sl'],
        'sv' => ['Swedish', 'reklamationsrätt'],
    ];

    /**
     * @return list<string>
     */
    public function getCodes(): array
    {
        return array_keys(self::LANGUAGES);
    }

    public function isSupported(string $code): bool
    {
        return array_key_exists($code, self::LANGUAGES);
    }

    public function getName(string $code): string
    {
        return self::LANGUAGES[$this->assertSupported($code)][0];
    }

    /**
     * Clickable link target, identical to the QR code destination of the notice.
     */
    public function getYourEuropeUrl(string $code): string
    {
        $segment = self::LANGUAGES[$this->assertSupported($code)][1];

        return 'https://' . self::YOUR_EUROPE_BASE . rawurlencode($segment);
    }

    /**
     * Human readable link text as printed on the notice, e.g. "europa.eu/youreurope/garantien".
     */
    public function getYourEuropeDisplayUrl(string $code): string
    {
        return self::YOUR_EUROPE_BASE . self::LANGUAGES[$this->assertSupported($code)][1];
    }

    /**
     * Notice file relative to the GARAN media assets, e.g. "notice/de.svg".
     */
    public function getNoticeFile(string $code, string $extension): string
    {
        if (!in_array($extension, self::NOTICE_EXTENSIONS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported notice file extension "%s".', $extension));
        }

        return MediaAssets::NOTICE_DIRECTORY . '/' . $this->assertSupported($code) . '.' . $extension;
    }

    /**
     * Maps a Magento locale such as "de_AT" to a supported language code, or null.
     */
    public function fromLocale(string $locale): ?string
    {
        $code = strtolower((string) strtok($locale, '_-'));

        return $this->isSupported($code) ? $code : null;
    }

    private function assertSupported(string $code): string
    {
        if (!$this->isSupported($code)) {
            throw new InvalidArgumentException(sprintf('Unsupported EU label language "%s".', $code));
        }

        return $code;
    }
}
