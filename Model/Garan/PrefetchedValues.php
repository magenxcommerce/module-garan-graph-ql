<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Garan;

/**
 * Request scoped store of raw attribute values and configurable parents loaded in bulk by {@see BatchLoader}.
 *
 * {@see Resolver} asks here before issuing its own per-product queries, so a GraphQL branch with many products costs
 * one query per EAV table instead of several per product. A value that was looked up and is absent is stored as null
 * and still counts as known.
 */
class PrefetchedValues
{
    /**
     * @var array<int, int> child id => parent id, 0 for "no configurable parent"
     */
    private array $parents = [];

    /**
     * @var array<int, array<int, array<string, mixed>>> store id => product id => code => raw value
     */
    private array $values = [];

    public function setParentId(int $productId, int $parentId): void
    {
        $this->parents[$productId] = $parentId;
    }

    /**
     * Configurable parent id, 0 for none, null when this product was never prefetched.
     */
    public function getParentId(int $productId): ?int
    {
        return $this->parents[$productId] ?? null;
    }

    public function setValue(int $productId, string $code, int $storeId, mixed $value): void
    {
        $this->values[$storeId][$productId][$code] = $value;
    }

    /**
     * Non-empty values of the codes, or null when any of them was never prefetched for this product and store.
     *
     * @param list<string> $codes
     * @return array<string, mixed>|null
     */
    public function getValues(int $productId, array $codes, int $storeId): ?array
    {
        $known = $this->values[$storeId][$productId] ?? null;
        if ($known === null) {
            return null;
        }

        $values = [];
        foreach ($codes as $code) {
            if (!array_key_exists($code, $known)) {
                return null;
            }
            if ($known[$code] !== null) {
                $values[$code] = $known[$code];
            }
        }

        return $values;
    }
}
