<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Email;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Request scoped hand-over between the order email observer and the mail transport plugin.
 *
 * Magento shares DI instances within a request, so observer and plugin see the same registry. The order confirmation
 * mail is prepared (Magento\Sales\Model\Order\Email\Sender::prepareTemplate, where the observer fills this registry)
 * and only afterwards built (TransportBuilder::getTransport, where the plugin empties it again), so nothing can leak
 * into a different email as long as every consumer takes and clears in one step.
 *
 * A send can fail between the two (SenderBuilder throws after the observer ran, and OrderSender swallows it), which
 * leaves documents queued. Under PHP-FPM the request ends and they are gone; on a long-lived application server the
 * request-state reset empties the registry so they never reach the next email built by the same process.
 */
class PendingAttachments implements ResetAfterRequestInterface
{
    /**
     * @var list<EmailAttachment>
     */
    private array $documents = [];

    public function add(EmailAttachment $document): void
    {
        $this->documents[] = $document;
    }

    /**
     * Whether a document is waiting to be attached to the next email built in this request.
     */
    public function hasPending(): bool
    {
        return $this->documents !== [];
    }

    /**
     * Returns every pending document and empties the registry.
     *
     * @return list<EmailAttachment>
     */
    public function takeAll(): array
    {
        $documents = $this->documents;
        $this->documents = [];

        return $documents;
    }

    public function clear(): void
    {
        $this->documents = [];
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->clear();
    }
}
