<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Resolver;

use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Render\NoticeRenderer;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `Query.garanNotice`: the official harmonised legal guarantee notice of the store language, or null when disabled.
 *
 * A root query of its own instead of StoreConfig fields: the storefront loads StoreConfig on every page, and a field
 * the backend does not know fails that whole document.
 */
class GaranNotice implements ResolverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly NoticeRenderer $noticeRenderer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $storeId = (int) $context->getExtensionAttributes()->getStore()->getId();
        if (!$this->config->isEnabled($storeId)) {
            return null;
        }

        try {
            return [
                'language' => $this->config->getLanguageCode($storeId),
                'svg_url' => $this->noticeRenderer->getSvgUrl($storeId),
                'png_url' => $this->noticeRenderer->getPngUrl($storeId),
                'link_url' => $this->noticeRenderer->getLinkUrl($storeId),
                'link_label' => $this->noticeRenderer->getLinkLabel($storeId),
                'alt_text' => $this->noticeRenderer->getAltText($storeId),
                'excluded_product_types' => $this->config->getExcludedProductTypes($storeId),
            ];
        } catch (Throwable $exception) {
            $this->logger->error('Magenx_GaranGraphQl: legal guarantee notice unavailable.', [
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
