<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Email;

use Magenx\GaranGraphQl\Api\Data\GaranLabelDataInterface;
use Magenx\GaranGraphQl\Api\GaranLabelResolverInterface;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Email\GraphicAttachments;
use Magenx\GaranGraphQl\Model\Garan\GaranLabelData;
use Magenx\GaranGraphQl\Model\Render\GaranPngRenderer;
use Magenx\GaranGraphQl\Model\Render\NoticeRenderer;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GraphicAttachmentsTest extends TestCase
{
    private const STORE_ID = 2;
    private const NOTICE_FILE = '/var/www/pub/media/garan/notice/de.png';

    private Config&MockObject $config;
    private NoticeRenderer&MockObject $noticeRenderer;
    private GaranPngRenderer&MockObject $pngRenderer;
    private GaranLabelResolverInterface&MockObject $resolver;
    private File&MockObject $fileDriver;
    private LoggerInterface&MockObject $logger;
    private GraphicAttachments $attachments;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->noticeRenderer = $this->createMock(NoticeRenderer::class);
        $this->noticeRenderer->method('getPngSourceFile')->willReturn(self::NOTICE_FILE);
        $this->pngRenderer = $this->createMock(GaranPngRenderer::class);
        $this->resolver = $this->createMock(GaranLabelResolverInterface::class);
        $this->fileDriver = $this->createMock(File::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->attachments = new GraphicAttachments(
            $this->config,
            $this->noticeRenderer,
            $this->pngRenderer,
            $this->resolver,
            $this->fileDriver,
            $this->logger
        );
    }

    public function testNoticeIsNotAttachedWhenSwitchedOff(): void
    {
        $this->config->method('isNoticeEmailAttachmentEnabled')->willReturn(false);
        $this->fileDriver->expects($this->never())->method('fileGetContents');

        self::assertNull($this->attachments->forNotice(self::STORE_ID));
    }

    public function testNoticeIsAttachedAsPng(): void
    {
        $this->config->method('isNoticeEmailAttachmentEnabled')->willReturn(true);
        $this->fileDriver->expects($this->once())
            ->method('fileGetContents')
            ->with(self::NOTICE_FILE)
            ->willReturn('PNG-BYTES');

        $attachment = $this->attachments->forNotice(self::STORE_ID);

        self::assertNotNull($attachment);
        self::assertSame(GraphicAttachments::NOTICE_FILE_NAME, $attachment->getName());
        self::assertSame('PNG-BYTES', $attachment->getContent());
        self::assertSame(GraphicAttachments::MIME_TYPE, $attachment->getMimeType());
    }

    public function testAnUnreadableNoticeIsLoggedAndSkipped(): void
    {
        $this->config->method('isNoticeEmailAttachmentEnabled')->willReturn(true);
        $this->fileDriver->method('fileGetContents')->willThrowException(new FileSystemException(__('gone')));
        $this->logger->expects($this->once())->method('warning');

        self::assertNull($this->attachments->forNotice(self::STORE_ID));
    }

    public function testLabelsAreNotAttachedWhenSwitchedOff(): void
    {
        $this->config->method('isGaranEmailAttachmentEnabled')->willReturn(false);
        $this->pngRenderer->expects($this->never())->method('getContents');

        self::assertSame([], $this->attachments->forGaranLabels($this->createOrder([]), self::STORE_ID));
    }

    public function testOneFileIsAttachedPerLabelledItem(): void
    {
        $this->config->method('isGaranEmailAttachmentEnabled')->willReturn(true);
        $this->pngRenderer->method('getContents')->willReturnCallback(
            static fn (GaranLabelDataInterface $label): string => 'PNG-' . $label->getSku()
        );
        $this->givenLabels([
            'item-1' => [$this->createLabel('SKU 1/A')],
            'item-2' => [$this->createLabel('SKU-2')],
        ]);

        $attachments = $this->attachments->forGaranLabels($this->createOrder(['item-1', 'item-2']), self::STORE_ID);

        self::assertCount(2, $attachments);
        self::assertSame('garan-label-SKU-1-A.png', $attachments[0]->getName());
        self::assertSame('PNG-SKU 1/A', $attachments[0]->getContent());
        self::assertSame('garan-label-SKU-2.png', $attachments[1]->getName());
    }

    public function testTheSameSkuIsAttachedOnlyOnce(): void
    {
        $this->config->method('isGaranEmailAttachmentEnabled')->willReturn(true);
        $this->pngRenderer->expects($this->once())->method('getContents')->willReturn('PNG');
        $this->givenLabels([
            'item-1' => [$this->createLabel('SKU-1')],
            'item-2' => [$this->createLabel('SKU-1')],
        ]);

        $attachments = $this->attachments->forGaranLabels($this->createOrder(['item-1', 'item-2']), self::STORE_ID);

        self::assertCount(1, $attachments);
    }

    public function testALabelWithoutAGraphicIsSkipped(): void
    {
        $this->config->method('isGaranEmailAttachmentEnabled')->willReturn(true);
        $this->pngRenderer->method('getContents')->willReturn(null);
        $this->givenLabels(['item-1' => [$this->createLabel('SKU-1')]]);

        self::assertSame([], $this->attachments->forGaranLabels($this->createOrder(['item-1']), self::STORE_ID));
    }

    /**
     * @param array<string, list<GaranLabelDataInterface>> $labelsByItem
     */
    private function givenLabels(array $labelsByItem): void
    {
        $this->resolver->method('forOrderItem')->willReturnCallback(
            static fn (OrderItem $item): array => $labelsByItem[(string) $item->getData('key')] ?? []
        );
    }

    /**
     * @param list<string> $itemKeys
     */
    private function createOrder(array $itemKeys): Order&MockObject
    {
        $items = [];
        foreach ($itemKeys as $key) {
            $item = $this->getMockBuilder(OrderItem::class)->disableOriginalConstructor()->getMock();
            $item->method('getData')->willReturn($key);
            $items[] = $item;
        }

        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->getMock();
        $order->method('getAllVisibleItems')->willReturn($items);

        return $order;
    }

    private function createLabel(string $sku): GaranLabelData
    {
        return new GaranLabelData(7, 'Product ' . $sku, $sku, 'Brand', 'Model', 5.0, 'https://example.com/terms');
    }
}
