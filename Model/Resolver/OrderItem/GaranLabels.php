<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Resolver\OrderItem;

use Magenx\GaranGraphQl\Api\GaranLabelResolverInterface;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Garan\LabelPresenter;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Model\Order\Item as OrderItem;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `OrderItemInterface.garan_labels`: the labels frozen on the order item when the order was placed.
 *
 * Reads the snapshot column already loaded with the item, so it costs no query and shows what the customer was
 * shown at purchase even if the product data changed since. Null when the module is disabled.
 */
class GaranLabels implements ResolverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly GaranLabelResolverInterface $labelResolver,
        private readonly LabelPresenter $presenter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $item = $value['model'] ?? null;
        if (!$item instanceof OrderItem) {
            return null;
        }

        $storeId = (int) $item->getStoreId();
        if (!$this->config->isEnabled($storeId)) {
            return null;
        }

        try {
            $labels = [];
            foreach ($this->labelResolver->forOrderItem($item) as $label) {
                $labels[] = $this->presenter->toGraphQl($label, $storeId);
            }

            return $labels;
        } catch (Throwable $exception) {
            $this->logger->error(
                'Magenx_GaranGraphQl: GARAN labels of an order item could not be resolved.',
                ['exception' => $exception, 'item_id' => $item->getId()]
            );

            return [];
        }
    }
}
