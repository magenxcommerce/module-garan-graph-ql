<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Email;

use Magenx\GaranGraphQl\Model\Email\PendingAttachments;
use Magenx\GaranGraphQl\Model\Email\EmailAttachment;
use PHPUnit\Framework\TestCase;

class PendingAttachmentsTest extends TestCase
{
    private PendingAttachments $registry;

    protected function setUp(): void
    {
        $this->registry = new PendingAttachments();
    }

    public function testEmptyRegistryHasNothingPending(): void
    {
        $this->assertFalse($this->registry->hasPending());
        $this->assertSame([], $this->registry->takeAll());
    }

    public function testAddedDocumentsAreReturnedInOrder(): void
    {
        $first = new EmailAttachment('a.pdf', 'A', 'application/pdf');
        $second = new EmailAttachment('b.pdf', 'B', 'application/pdf');
        $this->registry->add($first);
        $this->registry->add($second);

        $this->assertTrue($this->registry->hasPending());
        $this->assertSame([$first, $second], $this->registry->takeAll());
    }

    public function testTakeAllEmptiesTheRegistry(): void
    {
        $this->registry->add(new EmailAttachment('a.pdf', 'A', 'application/pdf'));
        $this->registry->takeAll();

        $this->assertFalse($this->registry->hasPending());
        $this->assertSame([], $this->registry->takeAll());
    }

    public function testClearDropsPendingDocuments(): void
    {
        $this->registry->add(new EmailAttachment('a.pdf', 'A', 'application/pdf'));
        $this->registry->clear();

        $this->assertFalse($this->registry->hasPending());
        $this->assertSame([], $this->registry->takeAll());
    }

    /**
     * A send that failed before the transport was built leaves its documents queued; on a long-lived application
     * server they must not reach the next email the same process builds.
     */
    public function testRequestStateResetDropsDocumentsLeftByAFailedSend(): void
    {
        $this->registry->add(new EmailAttachment('a.pdf', 'A', 'application/pdf'));
        $this->registry->_resetState();

        $this->assertFalse($this->registry->hasPending());
        $this->assertSame([], $this->registry->takeAll());
    }
}
