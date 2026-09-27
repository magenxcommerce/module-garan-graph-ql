<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Render;

use Magenx\GaranGraphQl\Model\Asset\MediaAssets;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Language\LanguageRegistry;

/**
 * URLs and texts of the harmonised notice on the legal guarantee for one store view.
 */
class NoticeRenderer
{
    public function __construct(
        private readonly Config $config,
        private readonly LanguageRegistry $languageRegistry,
        private readonly MediaAssets $mediaAssets
    ) {
    }

    /**
     * Official SVG of the store language for web pages, served from the media URL.
     */
    public function getSvgUrl(?int $storeId = null): string
    {
        return $this->mediaAssets->getUrl($this->getNoticeFile('svg', $storeId), $storeId);
    }

    /**
     * Official PNG of the store language for emails: secure media URL of the store, also inside store emulation.
     */
    public function getPngUrl(?int $storeId = null): string
    {
        return $this->mediaAssets->getUrl($this->getNoticeFile('png', $storeId), $storeId);
    }

    /**
     * Absolute path of the official PNG in pub/media, for attaching it to an email.
     */
    public function getPngSourceFile(?int $storeId = null): string
    {
        return $this->mediaAssets->getAbsolutePath($this->getNoticeFile('png', $storeId));
    }

    /**
     * Link target, identical to the QR code destination of the notice.
     */
    public function getLinkUrl(?int $storeId = null): string
    {
        return $this->languageRegistry->getYourEuropeUrl($this->config->getLanguageCode($storeId));
    }

    /**
     * Visible link text as printed on the notice, e.g. "europa.eu/youreurope/garantien".
     */
    public function getLinkLabel(?int $storeId = null): string
    {
        return $this->languageRegistry->getYourEuropeDisplayUrl($this->config->getLanguageCode($storeId));
    }

    public function getAltText(?int $storeId = null): string
    {
        return (string) __(
            // phpcs:ignore Generic.Files.LineLength.TooLong, Magento2.Files.LineLength.MaxExceeded
            'EU legal guarantee notice: minimum two-year legal guarantee protection for goods sold in the European Union. More information: %1',
            $this->getLinkLabel($storeId)
        );
    }

    private function getNoticeFile(string $extension, ?int $storeId): string
    {
        return $this->languageRegistry->getNoticeFile($this->config->getLanguageCode($storeId), $extension);
    }
}
