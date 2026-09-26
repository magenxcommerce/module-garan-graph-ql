<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Api;

use Magenx\GaranGraphQl\Api\Data\GaranLabelDataInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Sales\Model\Order\Item as OrderItem;

/**
 * Resolves EU GARAN label data. Invalid or incomplete product data never yields a label.
 */
interface GaranLabelResolverInterface
{
    /**
     * Label of a simple product, or null if the product does not qualify.
     */
    public function forProduct(ProductInterface $product, ?int $storeId = null): ?GaranLabelDataInterface;

    /**
     * Labels of all qualifying simple products behind a visible quote item:
     * the item itself (simple), the selected child (configurable) or every child (bundle).
     *
     * @return list<GaranLabelDataInterface>
     */
    public function forQuoteItem(AbstractItem $item): array;

    /**
     * Labels stored on the order item at order placement (snapshot), same semantics as forQuoteItem().
     *
     * @return list<GaranLabelDataInterface>
     */
    public function forOrderItem(OrderItem $item): array;
}
