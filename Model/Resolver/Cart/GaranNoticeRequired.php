<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Resolver\Cart;

use Magenx\GaranGraphQl\Model\Config;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Model\Quote;

/**
 * `Cart.garan_notice_required`: whether the legal guarantee notice belongs next to this cart, i.e. the module is
 * enabled and at least one line is a good (not one of the excluded product types such as gift cards).
 */
class GaranNoticeRequired implements ResolverInterface
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $cart = $value['model'] ?? null;
        if (!$cart instanceof Quote) {
            return false;
        }

        $storeId = (int) $cart->getStoreId();
        if (!$this->config->isEnabled($storeId)) {
            return false;
        }

        $excluded = $this->config->getExcludedProductTypes($storeId);
        foreach ($cart->getAllVisibleItems() as $item) {
            if (!in_array((string) $item->getProductType(), $excluded, true)) {
                return true;
            }
        }

        return false;
    }
}
