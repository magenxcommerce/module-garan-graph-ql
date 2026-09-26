<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ModelIdentifierSource implements OptionSourceInterface
{
    public const GARAN_ATTRIBUTE = 'garan_attribute';
    public const PRODUCT_NAME = 'product_name';
    public const SOURCES = [self::GARAN_ATTRIBUTE, self::PRODUCT_NAME];

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::GARAN_ATTRIBUTE, 'label' => __('GARAN Model Identifier attribute of the product')],
            ['value' => self::PRODUCT_NAME, 'label' => __('Product name')],
        ];
    }
}
