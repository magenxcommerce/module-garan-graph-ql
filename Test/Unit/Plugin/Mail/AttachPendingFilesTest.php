<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Plugin\Mail;

use Magenx\GaranGraphQl\Model\Email\PendingAttachments;
use Magenx\GaranGraphQl\Model\Email\EmailAttachment;
use Magenx\GaranGraphQl\Plugin\Mail\AttachPendingFiles;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\MessageInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Message as SymfonyMessage;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\TextPart;

class AttachPendingFilesTest extends TestCase
{
    private const CONTENT = '%PDF-1.7 binary';
    private const NAME = 'Garantiebedingungen.pdf';

    private PendingAttachments $pendingAttachments;
    private LoggerInterface&MockObject $logger;
    private TransportBuilder&MockObject $subject;
    private AttachPendingFiles $plugin;

    protected function setUp(): void
    {
        $this->pendingAttachments = new PendingAttachments();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = $this->createMock(TransportBuilder::class);

        $this->plugin = new AttachPendingFiles($this->pendingAttachments, $this->logger);
    }

    public function testEmptyRegistryLeavesTheMessageUntouched(): void
    {
        $textPart = new TextPart('Order confirmation');
        $symfonyMessage = new SymfonyMessage(new Headers(), $textPart);
        $transport = $this->createTransport($symfonyMessage);
        $this->logger->expects($this->never())->method('error');

        $result = $this->plugin->afterGetTransport($this->subject, $transport);

        $this->assertSame($transport, $result);
        $this->assertSame($textPart, $symfonyMessage->getBody());
    }

    public function testDocumentIsAttachedToTheExistingBody(): void
    {
        $textPart = new TextPart('Order confirmation');
        $symfonyMessage = new SymfonyMessage(new Headers(), $textPart);
        $this->pendingAttachments->add(new EmailAttachment(self::NAME, self::CONTENT, 'application/pdf'));

        $this->plugin->afterGetTransport($this->subject, $this->createTransport($symfonyMessage));

        $body = $symfonyMessage->getBody();
        $this->assertInstanceOf(MixedPart::class, $body);
        $parts = $body->getParts();
        $this->assertCount(2, $parts);
        $this->assertSame($textPart, $parts[0]);
        $this->assertInstanceOf(DataPart::class, $parts[1]);
        $this->assertSame(self::NAME, $parts[1]->getFilename());
        $this->assertSame('application/pdf', $parts[1]->getContentType());
        $this->assertSame(self::CONTENT, $parts[1]->getBody());
        $this->assertFalse($this->pendingAttachments->hasPending());
    }

    public function testExistingAttachmentsAreNotNested(): void
    {
        $textPart = new TextPart('Order confirmation');
        $foreignPart = new DataPart('invoice', 'invoice.pdf', 'application/pdf');
        $symfonyMessage = new SymfonyMessage(new Headers(), new MixedPart($textPart, $foreignPart));
        $this->pendingAttachments->add(new EmailAttachment(self::NAME, self::CONTENT, 'application/pdf'));

        $this->plugin->afterGetTransport($this->subject, $this->createTransport($symfonyMessage));

        $body = $symfonyMessage->getBody();
        $this->assertInstanceOf(MixedPart::class, $body);
        $parts = $body->getParts();
        $this->assertCount(3, $parts);
        $this->assertSame($textPart, $parts[0]);
        $this->assertSame($foreignPart, $parts[1]);
        $this->assertSame(self::NAME, $parts[2]->getFilename());
    }

    public function testSymfonyEmailUsesItsOwnAttachmentApi(): void
    {
        $email = new SymfonyEmail();
        $email->text('Order confirmation');
        $this->pendingAttachments->add(new EmailAttachment(self::NAME, self::CONTENT, 'application/pdf'));

        $this->plugin->afterGetTransport($this->subject, $this->createTransport($email));

        $attachments = $email->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame(self::NAME, $attachments[0]->getFilename());
    }

    public function testMessageWithoutSymfonyMessageIsLoggedAndSent(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('getMessage')->willReturn($this->createMock(MessageInterface::class));
        $this->pendingAttachments->add(new EmailAttachment(self::NAME, self::CONTENT, 'application/pdf'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame($transport, $this->plugin->afterGetTransport($this->subject, $transport));
        $this->assertFalse($this->pendingAttachments->hasPending());
    }

    public function testUnexpectedTransportIsReturnedUnchanged(): void
    {
        $this->pendingAttachments->add(new EmailAttachment(self::NAME, self::CONTENT, 'application/pdf'));
        $this->logger->expects($this->never())->method('error');

        $this->assertNull($this->plugin->afterGetTransport($this->subject, null));
        $this->assertFalse($this->pendingAttachments->hasPending());
    }

    public function testExceptionIsLoggedAndTheTransportIsStillReturned(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('getMessage')->willThrowException(new RuntimeException('message gone'));
        $this->pendingAttachments->add(new EmailAttachment(self::NAME, self::CONTENT, 'application/pdf'));
        $this->logger->expects($this->once())->method('error');

        $this->assertSame($transport, $this->plugin->afterGetTransport($this->subject, $transport));
        $this->assertFalse($this->pendingAttachments->hasPending());
    }

    private function createTransport(SymfonyMessage $symfonyMessage): TransportInterface&MockObject
    {
        $message = $this->createMock(EmailMessage::class);
        $message->method('getSymfonyMessage')->willReturn($symfonyMessage);
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('getMessage')->willReturn($message);

        return $transport;
    }
}
