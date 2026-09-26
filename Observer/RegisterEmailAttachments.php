<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Observer;

use Magenx\GaranGraphQl\Api\GaranLabelResolverInterface;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Email\GraphicAttachments;
use Magenx\GaranGraphQl\Model\Email\PendingAttachments;
use Magenx\GaranGraphQl\Model\Email\TermsDocument;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers the files that travel with the order confirmation email: the guarantee terms document, the notice
 * graphic and the GARAN label graphics. Each has its own switch.
 *
 * The producer's guarantee statement must reach the consumer on a durable medium at the latest at delivery
 * (§ 9a (3) KSchG, § 479 (2) BGB, Art. 17(2) Directive (EU) 2019/771); a link is not enough (CJEU C-49/11), so the
 * configured file is attached to the confirmation mail whenever the order contains a product with a GARAN label.
 * The two graphics are attached because an email client that blocks remote images shows nothing of them inline.
 *
 * "email_order_set_template_vars_before" is dispatched by Magento\Sales\Model\Order\Email\Sender\OrderSender only -
 * invoice, shipment, credit memo and comment senders each dispatch their own event - so no further restriction is
 * needed: the attachment cannot end up on any other sales email.
 *
 * Failures are logged and swallowed; the order confirmation must never fail because of this module.
 */
class RegisterEmailAttachments implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly GaranLabelResolverInterface $resolver,
        private readonly TermsDocument $termsDocument,
        private readonly GraphicAttachments $graphicAttachments,
        private readonly PendingAttachments $pendingAttachments,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $order = $this->getOrder($observer);
            if (!$order instanceof Order) {
                return;
            }

            $storeId = (int) $order->getStoreId();
            $this->addTermsDocument($order, $storeId);

            $notice = $this->graphicAttachments->forNotice($storeId);
            if ($notice !== null) {
                $this->pendingAttachments->add($notice);
            }
            foreach ($this->graphicAttachments->forGaranLabels($order, $storeId) as $label) {
                $this->pendingAttachments->add($label);
            }
        } catch (Throwable $exception) {
            $this->logger->error(
                'Magenx_GaranGraphQl: the email attachments could not be registered.',
                ['exception' => $exception]
            );
        }
    }

    private function addTermsDocument(Order $order, int $storeId): void
    {
        if (!$this->config->isTermsAttachmentEnabled($storeId) || !$this->hasGaranLabel($order)) {
            return;
        }

        $document = $this->termsDocument->resolve($storeId);
        if ($document !== null) {
            $this->pendingAttachments->add($document);
        }
    }

    /**
     * The order carried by the transport object; "transport" is the deprecated alias of "transportObject".
     */
    private function getOrder(Observer $observer): ?Order
    {
        $transport = $observer->getData('transportObject') ?? $observer->getData('transport');
        if (!$transport instanceof DataObject) {
            return null;
        }

        $order = $transport->getData('order');

        return $order instanceof Order ? $order : null;
    }

    /**
     * Reads the GARAN snapshot written at order placement, for visible items only (children carry their own).
     */
    private function hasGaranLabel(Order $order): bool
    {
        foreach ($order->getAllVisibleItems() as $item) {
            if ($this->resolver->forOrderItem($item) !== []) {
                return true;
            }
        }

        return false;
    }
}
