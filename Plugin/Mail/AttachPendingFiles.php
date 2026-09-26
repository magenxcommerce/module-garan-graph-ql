<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Plugin\Mail;

use Magenx\GaranGraphQl\Model\Email\PendingAttachments;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message as SymfonyMessage;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Throwable;

/**
 * Attaches the documents registered for the current email to the message the transport is about to send.
 *
 * Works without a third-party attachment extension on purpose: the durable medium
 * obligation must not depend on it.
 *
 * Magento\Framework\Mail\Transport::sendMessage() hands exactly the object returned by
 * EmailMessage::getSymfonyMessage() to the Symfony mailer, so mutating that object in place is enough - the message is
 * never rebuilt here, and the existing body part is reused unchanged so that other modules' body handling survives.
 *
 * Note that Magento\Framework\Mail\MimeMessage builds a plain Symfony\Component\Mime\Message, not an Email, so
 * Email::attach() is usually not available; the body is then wrapped in a MixedPart, the same way
 * established attachment extensions do it on Magento 2.4.8.
 */
class AttachPendingFiles
{
    public function __construct(
        private readonly PendingAttachments $pendingAttachments,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Sends the email even when attaching fails; a missing attachment is logged, a missing email is not acceptable.
     *
     * @param TransportBuilder $subject
     * @param TransportInterface|mixed $result
     * @return TransportInterface|mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetTransport(TransportBuilder $subject, $result)
    {
        try {
            $documents = $this->pendingAttachments->takeAll();
            if ($documents === [] || !$result instanceof TransportInterface) {
                return $result;
            }

            $message = $result->getMessage();
            if (!method_exists($message, 'getSymfonyMessage')) {
                $this->logger->warning(
                    'Magenx_GaranGraphQl: attachments were dropped, the mail message exposes no Symfony message.',
                    ['message_class' => get_class($message)]
                );

                return $result;
            }

            $parts = [];
            foreach ($documents as $document) {
                $parts[] = new DataPart($document->getContent(), $document->getName(), $document->getMimeType());
            }
            $this->addParts($message->getSymfonyMessage(), $parts);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Magenx_GaranGraphQl: attachments could not be added to the email.',
                ['exception' => $exception]
            );
        } finally {
            $this->pendingAttachments->clear();
        }

        return $result;
    }

    /**
     * @param list<DataPart> $parts
     */
    private function addParts(SymfonyMessage $message, array $parts): void
    {
        if ($message instanceof Email) {
            foreach ($parts as $part) {
                $message->addPart($part);
            }

            return;
        }

        $body = $message->getBody();
        if ($body === null) {
            $this->logger->warning('Magenx_GaranGraphQl: attachments were dropped, the email has no body part.');

            return;
        }

        // Keep a single multipart/mixed level when something has already attached a file to this message.
        $existing = $body instanceof MixedPart ? $body->getParts() : [$body];
        $message->setBody(new MixedPart(...$existing, ...$parts));
    }
}
