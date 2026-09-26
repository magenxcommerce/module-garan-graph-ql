<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Garan;

use Magenx\GaranGraphQl\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\DataObject;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\Store;

/**
 * Loads the GARAN attribute values and configurable parents of many products at once.
 *
 * Grid and cart branches hand every product to one batch resolver; this turns what {@see Resolver} would ask per
 * product (raw values, parent lookup, parent values) into one parent query plus one query per EAV backend table.
 * The product's own values are set on the product model (null when absent, which Resolver treats as "loaded and
 * empty"), the parents' values go to {@see PrefetchedValues}.
 */
class BatchLoader
{
    public function __construct(
        private readonly Config $config,
        private readonly ProductResource $productResource,
        private readonly MetadataPool $metadataPool,
        private readonly PrefetchedValues $prefetchedValues
    ) {
    }

    /**
     * @param list<ProductInterface> $products
     */
    public function prefetch(array $products, int $storeId): void
    {
        $byId = [];
        foreach ($products as $product) {
            $id = (int) $product->getId();
            if ($id > 0) {
                $byId[$id] = $product;
            }
        }
        if ($byId === []) {
            return;
        }

        $codes = Attributes::ALL;
        $brandAttribute = $this->config->getBrandAttribute($storeId);
        if ($brandAttribute !== '' && !in_array($brandAttribute, $codes, true)) {
            $codes[] = $brandAttribute;
        }

        $parents = $this->loadParents(array_keys($byId));
        $values = $this->loadValues(array_values(array_unique([...array_keys($byId), ...$parents])), $codes, $storeId);

        foreach ($byId as $id => $product) {
            $this->prefetchedValues->setParentId($id, $parents[$id] ?? 0);
            foreach ($codes as $code) {
                $value = $values[$id][$code] ?? null;
                $this->prefetchedValues->setValue($id, $code, $storeId, $value);
                if ($product instanceof DataObject && !$product->hasData($code)) {
                    $product->setData($code, $value);
                }
            }
        }
        foreach (array_unique($parents) as $parentId) {
            foreach ($codes as $code) {
                $this->prefetchedValues->setValue($parentId, $code, $storeId, $values[$parentId][$code] ?? null);
            }
        }
    }

    /**
     * @param list<int> $childIds
     * @return array<int, int> child id => first configurable parent id
     */
    private function loadParents(array $childIds): array
    {
        $connection = $this->productResource->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $select = $connection->select()
            ->from(['link' => $this->productResource->getTable('catalog_product_super_link')], ['product_id'])
            ->join(
                ['parent' => $this->productResource->getTable('catalog_product_entity')],
                'parent.' . $linkField . ' = link.parent_id',
                ['entity_id']
            )
            ->where('link.product_id IN (?)', $childIds)
            ->order('parent.entity_id ASC');

        $parents = [];
        foreach ($connection->fetchAll($select) as $row) {
            $parents[(int) $row['product_id']] ??= (int) $row['entity_id'];
        }

        return $parents;
    }

    /**
     * Store view value, else default value, of every code for every product.
     *
     * @param list<int> $productIds
     * @param list<string> $codes
     * @return array<int, array<string, mixed>>
     */
    private function loadValues(array $productIds, array $codes, int $storeId): array
    {
        $byTable = [];
        $static = [];
        foreach ($codes as $code) {
            $attribute = $this->productResource->getAttribute($code);
            if (!$attribute instanceof AbstractAttribute) {
                continue;
            }
            if ($attribute->isStatic()) {
                $static[] = $code;
                continue;
            }
            $byTable[(string) $attribute->getBackendTable()][(int) $attribute->getAttributeId()] = $code;
        }

        $connection = $this->productResource->getConnection();
        $entityTable = $this->productResource->getTable('catalog_product_entity');
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $values = [];

        if ($static !== []) {
            $select = $connection->select()
                ->from($entityTable, ['entity_id', ...$static])
                ->where('entity_id IN (?)', $productIds);
            foreach ($connection->fetchAll($select) as $row) {
                foreach ($static as $code) {
                    $values[(int) $row['entity_id']][$code] = $row[$code];
                }
            }
        }

        $storeIds = array_values(array_unique([Store::DEFAULT_STORE_ID, $storeId]));
        foreach ($byTable as $table => $attributeCodes) {
            $select = $connection->select()
                ->from(['value' => $table], ['attribute_id', 'store_id', 'value'])
                ->join(['entity' => $entityTable], 'entity.' . $linkField . ' = value.' . $linkField, ['entity_id'])
                ->where('entity.entity_id IN (?)', $productIds)
                ->where('value.attribute_id IN (?)', array_keys($attributeCodes))
                ->where('value.store_id IN (?)', $storeIds)
                // Default rows first, so the store view row overwrites them below.
                ->order('value.store_id ASC');
            foreach ($connection->fetchAll($select) as $row) {
                $code = $attributeCodes[(int) $row['attribute_id']] ?? null;
                if ($code !== null) {
                    $values[(int) $row['entity_id']][$code] = $row['value'];
                }
            }
        }

        return $values;
    }
}
