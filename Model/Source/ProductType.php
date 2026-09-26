<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Source;

use Magento\Catalog\Model\Product\Type;
use Magento\Framework\Data\OptionSourceInterface;

class ProductType implements OptionSourceInterface
{
    public function __construct(
        private readonly Type $productType
    ) {
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->productType->getOptionArray() as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return $options;
    }
}
