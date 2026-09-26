<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Email;

use Magenx\GaranGraphQl\Api\Data\GaranLabelDataInterface;
use Magenx\GaranGraphQl\Api\GaranLabelResolverInterface;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Render\GaranPngRenderer;
use Magenx\GaranGraphQl\Model\Render\NoticeRenderer;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the notice and GARAN graphics as email attachments.
 *
 * The same graphics are shown inline in the confirmation; as files they also survive an email client that blocks
 * remote images, and they can be filed away by the customer. Both are independent switches, so a shop can send the
 * graphic inline, attached, both or neither.
 *
 * Nothing here ever throws: a graphic that cannot be read must not stop the order confirmation from being sent.
 */
class GraphicAttachments
{
    public const MIME_TYPE = 'image/png';
    public const NOTICE_FILE_NAME = 'legal-guarantee-notice.png';

    private const GARAN_FILE_PREFIX = 'garan-label';

    public function __construct(
        private readonly Config $config,
        private readonly NoticeRenderer $noticeRenderer,
        private readonly GaranPngRenderer $pngRenderer,
        private readonly GaranLabelResolverInterface $resolver,
        private readonly File $fileDriver,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The official notice graphic of the store language, or null when it is switched off or unreadable.
     */
    public function forNotice(int $storeId): ?EmailAttachment
    {
        if (!$this->config->isNoticeEmailAttachmentEnabled($storeId)) {
            return null;
        }

        try {
            $contents = $this->fileDriver->fileGetContents($this->noticeRenderer->getPngSourceFile($storeId));
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Magenx_GaranGraphQl: the notice graphic could not be attached.',
                ['exception' => $exception, 'store_id' => $storeId]
            );

            return null;
        }

        return $contents === '' ? null : new EmailAttachment(self::NOTICE_FILE_NAME, $contents, self::MIME_TYPE);
    }

    /**
     * One label graphic per labelled order item, named after its SKU so the customer can tell them apart.
     *
     * @return list<EmailAttachment>
     */
    public function forGaranLabels(Order $order, int $storeId): array
    {
        if (!$this->config->isGaranEmailAttachmentEnabled($storeId)) {
            return [];
        }

        $attachments = [];
        foreach ($order->getAllVisibleItems() as $item) {
            foreach ($this->resolver->forOrderItem($item) as $label) {
                $name = $this->getFileName($label);
                if (isset($attachments[$name])) {
                    continue;
                }

                $contents = $this->pngRenderer->getContents($label, GaranPngRenderer::VARIANT_FULL, $storeId);
                if ($contents !== null) {
                    $attachments[$name] = new EmailAttachment($name, $contents, self::MIME_TYPE);
                }
            }
        }

        return array_values($attachments);
    }

    /**
     * SKUs may carry characters an email client will not accept in a file name, so only the harmless ones survive.
     */
    private function getFileName(GaranLabelDataInterface $label): string
    {
        $sku = preg_replace('/[^A-Za-z0-9._-]+/', '-', $label->getSku()) ?? '';
        $sku = trim($sku, '-');

        return $sku === ''
            ? self::GARAN_FILE_PREFIX . '.png'
            : self::GARAN_FILE_PREFIX . '-' . $sku . '.png';
    }
}
