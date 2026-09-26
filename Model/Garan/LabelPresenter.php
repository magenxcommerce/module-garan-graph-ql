<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Garan;

use Magenx\GaranGraphQl\Api\Data\GaranLabelDataInterface;
use Magenx\GaranGraphQl\Model\Language\LanguageRegistry;
use Magenx\GaranGraphQl\Model\Render\GaranPngRenderer;

/**
 * Turns resolved GARAN label data into what the order email and the GraphQL `GaranLabel` type show.
 */
class LabelPresenter
{
    public function __construct(
        private readonly GaranPngRenderer $pngRenderer
    ) {
    }

    /**
     * Accessible text carrying the complete label information, e.g. for alt attributes and dialog names.
     */
    public function getAccessibleLabel(GaranLabelDataInterface $label): string
    {
        return (string) __(
            'GARAN – producer guarantee %1 years, %2 %3',
            $label->getFormattedDuration(),
            $label->getBrand(),
            $label->getModelIdentifier()
        );
    }

    /**
     * Field values of the GraphQL `GaranLabel` type. The PNG URLs are null when the image cannot be rendered;
     * the storefront then falls back to the accessible text.
     *
     * @return array<string, mixed>
     */
    public function toGraphQl(GaranLabelDataInterface $label, int $storeId): array
    {
        return [
            'sku' => $label->getSku(),
            'product_name' => $label->getProductName(),
            'brand' => $label->getBrand(),
            'model_identifier' => $label->getModelIdentifier(),
            'duration_years' => $label->getDurationYears(),
            'formatted_duration' => $label->getFormattedDuration(),
            'terms_url' => $label->getTermsUrl(),
            'image_url' => $this->pngRenderer->getUrl($label, GaranPngRenderer::VARIANT_FULL, $storeId),
            'nested_image_url' => $this->pngRenderer->getUrl($label, GaranPngRenderer::VARIANT_NESTED, $storeId),
            'alt_text' => $this->getAccessibleLabel($label),
            'info_url' => LanguageRegistry::GARAN_INFO_URL,
        ];
    }
}
