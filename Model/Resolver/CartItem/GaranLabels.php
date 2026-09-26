<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Resolver\CartItem;

use Magenx\GaranGraphQl\Api\GaranLabelResolverInterface;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Garan\BatchLoader;
use Magenx\GaranGraphQl\Model\Garan\LabelPresenter;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchResolverInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `CartItemInterface.garan_labels`: labels of the simple products behind a cart line (the item itself, the selected
 * variant of a configurable, every child of a bundle). Empty list when none qualifies; null when disabled.
 */
class GaranLabels implements BatchResolverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly BatchLoader $batchLoader,
        private readonly GaranLabelResolverInterface $labelResolver,
        private readonly LabelPresenter $presenter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function resolve(ContextInterface $context, Field $field, array $requests): BatchResponse
    {
        $response = new BatchResponse();
        $storeId = (int) $context->getExtensionAttributes()->getStore()->getId();
        $active = $this->config->isGaranActive($storeId);

        $products = [];
        foreach ($requests as $request) {
            $item = $request->getValue()['model'] ?? null;
            if (!$active || !$item instanceof AbstractItem) {
                continue;
            }
            foreach ([$item, ...$item->getChildren()] as $source) {
                $product = $source->getProduct();
                if ($product instanceof ProductInterface) {
                    $products[] = $product;
                }
            }
        }
        if ($products !== []) {
            try {
                $this->batchLoader->prefetch($products, $storeId);
            } catch (Throwable $exception) {
                $this->logger->error('Magenx_GaranGraphQl: GARAN prefetch failed.', ['exception' => $exception]);
            }
        }

        foreach ($requests as $request) {
            $item = $request->getValue()['model'] ?? null;
            $response->addResponse(
                $request,
                $active && $item instanceof AbstractItem ? $this->resolveOne($item, $storeId) : null
            );
        }

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveOne(AbstractItem $item, int $storeId): array
    {
        try {
            $labels = [];
            foreach ($this->labelResolver->forQuoteItem($item) as $label) {
                $labels[] = $this->presenter->toGraphQl($label, $storeId);
            }

            return $labels;
        } catch (Throwable $exception) {
            $this->logger->error(
                'Magenx_GaranGraphQl: GARAN labels of a cart item could not be resolved.',
                ['exception' => $exception, 'item_id' => $item->getId()]
            );

            return [];
        }
    }
}
