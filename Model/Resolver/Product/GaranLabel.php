<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Resolver\Product;

use Magenx\GaranGraphQl\Api\GaranLabelResolverInterface;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Garan\BatchLoader;
use Magenx\GaranGraphQl\Model\Garan\LabelPresenter;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchResolverInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `ProductInterface.garan_label`: the EU GARAN label of a product, or null.
 *
 * Batch resolver: every product of the branch is prefetched with one parent query and one query per EAV table,
 * then resolved from memory. Null when the label is disabled, the product does not qualify or anything fails.
 */
class GaranLabel implements BatchResolverInterface
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
            $product = $request->getValue()['model'] ?? null;
            if ($active && $product instanceof ProductInterface) {
                $products[] = $product;
            }
        }
        if ($products !== []) {
            try {
                $this->batchLoader->prefetch($products, $storeId);
            } catch (Throwable $exception) {
                // Resolver falls back to its own per-product queries.
                $this->logger->error('Magenx_GaranGraphQl: GARAN prefetch failed.', ['exception' => $exception]);
            }
        }

        foreach ($requests as $request) {
            $product = $request->getValue()['model'] ?? null;
            $response->addResponse(
                $request,
                $active && $product instanceof ProductInterface ? $this->resolveOne($product, $storeId) : null
            );
        }

        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveOne(ProductInterface $product, int $storeId): ?array
    {
        try {
            $label = $this->labelResolver->forProduct($product, $storeId);

            return $label === null ? null : $this->presenter->toGraphQl($label, $storeId);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Magenx_GaranGraphQl: GARAN label could not be resolved.',
                ['exception' => $exception, 'product_id' => $product->getId()]
            );

            return null;
        }
    }
}
