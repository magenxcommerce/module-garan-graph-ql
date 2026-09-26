<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Garan;

use Magenx\GaranGraphQl\Api\Data\GaranLabelDataInterface;

class GaranLabelData implements GaranLabelDataInterface
{
    public function __construct(
        private readonly int $productId,
        private readonly string $productName,
        private readonly string $sku,
        private readonly string $brand,
        private readonly string $modelIdentifier,
        private readonly float $durationYears,
        private readonly string $termsUrl
    ) {
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getBrand(): string
    {
        return $this->brand;
    }

    public function getModelIdentifier(): string
    {
        return $this->modelIdentifier;
    }

    public function getDurationYears(): float
    {
        return $this->durationYears;
    }

    public function getFormattedDuration(): string
    {
        if (fmod($this->durationYears, 1.0) === 0.0) {
            return (string) (int) $this->durationYears;
        }

        return number_format($this->durationYears, 1, ',', '');
    }

    public function getTermsUrl(): string
    {
        return $this->termsUrl;
    }

    public function toArray(): array
    {
        return [
            self::PRODUCT_ID => $this->productId,
            self::PRODUCT_NAME => $this->productName,
            self::SKU => $this->sku,
            self::BRAND => $this->brand,
            self::MODEL_IDENTIFIER => $this->modelIdentifier,
            self::DURATION_YEARS => $this->durationYears,
            self::TERMS_URL => $this->termsUrl,
        ];
    }
}
