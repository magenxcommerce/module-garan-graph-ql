<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Plugin\Quote;

use Magenx\GaranGraphQl\Api\Data\GaranLabelDataInterface;
use Magenx\GaranGraphQl\Api\GaranLabelResolverInterface;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Garan\Attributes;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Magento\Sales\Api\Data\OrderItemInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stores the GARAN labels valid at order placement on the order item (AC-G8).
 * Only sets data on the converted item: no save, no order observers, dropship splitting stays untouched.
 */
class ToOrderItemPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly GaranLabelResolverInterface $garanLabelResolver,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterConvert(
        ToOrderItem $subject,
        OrderItemInterface $result,
        AbstractItem $item
    ): OrderItemInterface {
        if (!$result instanceof DataObject) {
            return $result;
        }

        try {
            if (!$this->config->isGaranActive($this->getStoreId($item))) {
                return $result;
            }

            $labels = $this->garanLabelResolver->forQuoteItem($item);
            if ($labels === []) {
                return $result;
            }

            $result->setData(
                Attributes::ORDER_ITEM_SNAPSHOT_COLUMN,
                $this->json->serialize(
                    array_map(static fn (GaranLabelDataInterface $label): array => $label->toArray(), $labels)
                )
            );
        } catch (Throwable $exception) {
            // A missing label must never prevent the order from being placed.
            $this->logger->error(
                'Magenx_GaranGraphQl: GARAN snapshot failed: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }

        return $result;
    }

    private function getStoreId(AbstractItem $item): int
    {
        $quote = $item->getQuote();
        if ($quote !== null && $quote->getStoreId() !== null) {
            return (int) $quote->getStoreId();
        }

        return (int) $item->getData('store_id');
    }
}
