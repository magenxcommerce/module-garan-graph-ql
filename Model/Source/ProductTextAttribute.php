<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Source;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Product attributes that can carry a brand name: free text and select lists.
 */
class ProductTextAttribute implements OptionSourceInterface
{
    private const INPUT_TYPES = ['text', 'select', 'textarea'];

    /**
     * @var array<int, array{value: string, label: string}>|null
     */
    private ?array $options = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('entity_type_id', ['eq' => $this->getProductEntityTypeId($collection)])
            ->addFieldToFilter('frontend_input', ['in' => self::INPUT_TYPES])
            ->setOrder('attribute_code', 'ASC');

        $options = [['value' => '', 'label' => (string) __('-- Please select --')]];
        foreach ($collection as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            $label = trim((string) $attribute->getDefaultFrontendLabel());
            $options[] = [
                'value' => $code,
                'label' => $label === '' ? $code : sprintf('%s (%s)', $label, $code),
            ];
        }

        return $this->options = $options;
    }

    private function getProductEntityTypeId(object $collection): int
    {
        $connection = $collection->getConnection();
        $select = $connection->select()
            ->from($collection->getTable('eav_entity_type'), ['entity_type_id'])
            ->where('entity_type_code = ?', Product::ENTITY);

        return (int) $connection->fetchOne($select);
    }
}
