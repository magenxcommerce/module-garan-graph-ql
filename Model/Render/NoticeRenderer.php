<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Render;

use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Language\LanguageRegistry;
use Magento\Framework\App\Area;
use Magento\Framework\View\Asset\Repository as AssetRepository;

/**
 * URLs and texts of the harmonised notice on the legal guarantee for one store view.
 */
class NoticeRenderer
{
    public function __construct(
        private readonly Config $config,
        private readonly LanguageRegistry $languageRegistry,
        private readonly AssetRepository $assetRepository
    ) {
    }

    /**
     * Official SVG of the store language for web pages. The frontend area is named explicitly because GraphQL
     * requests run in the graphql area, which has no theme of its own.
     */
    public function getSvgUrl(?int $storeId = null): string
    {
        return $this->assetRepository->getUrlWithParams(
            $this->languageRegistry->getNoticeAssetId($this->config->getLanguageCode($storeId), 'svg'),
            ['area' => Area::AREA_FRONTEND, '_secure' => true]
        );
    }

    /**
     * Official PNG of the store language for emails: frontend area and secure URL, also inside store emulation.
     */
    public function getPngUrl(?int $storeId = null): string
    {
        return $this->assetRepository->getUrlWithParams(
            $this->languageRegistry->getNoticeAssetId($this->config->getLanguageCode($storeId), 'png'),
            ['area' => Area::AREA_FRONTEND, '_secure' => true]
        );
    }

    /**
     * Absolute path of the official PNG in the module directory, for attaching it to an email.
     */
    public function getPngSourceFile(?int $storeId = null): string
    {
        return $this->assetRepository
            ->createAsset(
                $this->languageRegistry->getNoticeAssetId($this->config->getLanguageCode($storeId), 'png'),
                ['area' => Area::AREA_FRONTEND]
            )
            ->getSourceFile();
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
}
