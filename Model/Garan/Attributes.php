<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Garan;

/**
 * Product attribute codes of the GARAN label fields and the order item snapshot column.
 */
class Attributes
{
    public const BRAND = 'garan_brand';
    public const MODEL_IDENTIFIER = 'garan_model_identifier';
    public const DURATION_YEARS = 'garan_duration_years';
    public const TERMS_URL = 'garan_terms_url';
    public const ALL = [self::BRAND, self::MODEL_IDENTIFIER, self::DURATION_YEARS, self::TERMS_URL];

    public const ORDER_ITEM_SNAPSHOT_COLUMN = 'magenx_garan_label';
}
