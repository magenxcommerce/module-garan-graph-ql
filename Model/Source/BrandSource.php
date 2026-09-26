<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class BrandSource implements OptionSourceInterface
{
    public const GARAN_ATTRIBUTE = 'garan_attribute';
    public const PRODUCT_ATTRIBUTE = 'product_attribute';
    public const CONFIG_VALUE = 'config_value';
    public const SOURCES = [self::GARAN_ATTRIBUTE, self::PRODUCT_ATTRIBUTE, self::CONFIG_VALUE];

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::GARAN_ATTRIBUTE, 'label' => __('GARAN Brand attribute of the product')],
            ['value' => self::PRODUCT_ATTRIBUTE, 'label' => __('Another product attribute')],
            ['value' => self::CONFIG_VALUE, 'label' => __('Fixed value below')],
        ];
    }
}
