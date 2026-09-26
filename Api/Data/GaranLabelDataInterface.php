<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Api\Data;

/**
 * Validated content of one EU GARAN label for one simple product.
 */
interface GaranLabelDataInterface
{
    public const PRODUCT_ID = 'product_id';
    public const PRODUCT_NAME = 'product_name';
    public const SKU = 'sku';
    public const BRAND = 'brand';
    public const MODEL_IDENTIFIER = 'model_identifier';
    public const DURATION_YEARS = 'duration_years';
    public const TERMS_URL = 'terms_url';

    public function getProductId(): int;

    public function getProductName(): string;

    public function getSku(): string;

    public function getBrand(): string;

    public function getModelIdentifier(): string;

    public function getDurationYears(): float;

    /**
     * Duration as printed on the label: "5" or "4,5" (comma decimal separator, EU guidelines p. 22).
     */
    public function getFormattedDuration(): string;

    public function getTermsUrl(): string;

    /**
     * @return array{
     *     product_id: int, product_name: string, sku: string, brand: string,
     *     model_identifier: string, duration_years: float, terms_url: string
     * }
     */
    public function toArray(): array;
}
