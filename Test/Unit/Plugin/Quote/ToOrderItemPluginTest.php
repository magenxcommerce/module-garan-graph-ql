<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Plugin\Quote;

use Magenx\GaranGraphQl\Api\GaranLabelResolverInterface;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Garan\Attributes;
use Magenx\GaranGraphQl\Model\Garan\GaranLabelData;
use Magenx\GaranGraphQl\Plugin\Quote\ToOrderItemPlugin;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ToOrderItemPluginTest extends TestCase
{
    private const STORE_ID = 4;

    private Config&MockObject $config;
    private GaranLabelResolverInterface&MockObject $resolver;
    private LoggerInterface&MockObject $logger;
    private ToOrderItemPlugin $plugin;
    private ToOrderItem&MockObject $subject;
    private QuoteItem&MockObject $quoteItem;
    private OrderItem $orderItem;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->resolver = $this->createMock(GaranLabelResolverInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->plugin = new ToOrderItemPlugin($this->config, $this->resolver, new Json(), $this->logger);
        $this->subject = $this->createMock(ToOrderItem::class);

        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(self::STORE_ID);
        $this->quoteItem = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();
        $this->quoteItem->method('getQuote')->willReturn($quote);

        $this->orderItem = $this->getMockBuilder(OrderItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $this->orderItem->setData(['sku' => 'SKU-1', 'qty_ordered' => 2]);
    }

    public function testLeavesOrderItemUntouchedWhenGaranIsInactive(): void
    {
        $this->config->expects($this->once())->method('isGaranActive')->with(self::STORE_ID)->willReturn(false);
        $this->resolver->expects($this->never())->method('forQuoteItem');

        $result = $this->plugin->afterConvert($this->subject, $this->orderItem, $this->quoteItem);

        $this->assertSame($this->orderItem, $result);
        $this->assertSame(['sku' => 'SKU-1', 'qty_ordered' => 2], $result->getData());
    }

    public function testStoresLabelListAsJsonWhenActive(): void
    {
        $this->config->method('isGaranActive')->willReturn(true);
        $labels = [
            new GaranLabelData(21, 'Drill', 'SKU-21', 'Brand & Co', 'Model "A"', 4.5, 'https://example.com/a'),
            new GaranLabelData(22, 'Saw', 'SKU-22', 'Brand', 'Model B', 5.0, 'https://example.com/b'),
        ];
        $this->resolver->expects($this->once())
            ->method('forQuoteItem')
            ->with($this->quoteItem)
            ->willReturn($labels);

        $result = $this->plugin->afterConvert($this->subject, $this->orderItem, $this->quoteItem);

        $this->assertSame($this->orderItem, $result);
        // JSON has no float type: 5.0 is stored as 5, which DurationParser accepts when the snapshot is read.
        $snapshot = json_decode((string) $result->getData(Attributes::ORDER_ITEM_SNAPSHOT_COLUMN), true);
        $this->assertSame(
            json_decode((string) json_encode([$labels[0]->toArray(), $labels[1]->toArray()]), true),
            $snapshot
        );
        $this->assertSame(4.5, $snapshot[0]['duration_years']);
        $this->assertSame('SKU-1', $result->getData('sku'));
        $this->assertSame(2, $result->getData('qty_ordered'));
    }

    public function testLeavesOrderItemUntouchedWithoutLabels(): void
    {
        $this->config->method('isGaranActive')->willReturn(true);
        $this->resolver->method('forQuoteItem')->willReturn([]);

        $result = $this->plugin->afterConvert($this->subject, $this->orderItem, $this->quoteItem);

        $this->assertFalse($result->hasData(Attributes::ORDER_ITEM_SNAPSHOT_COLUMN));
    }

    public function testLogsAndLeavesOrderItemUntouchedWhenResolvingFails(): void
    {
        $this->config->method('isGaranActive')->willReturn(true);
        $this->resolver->method('forQuoteItem')->willThrowException(new RuntimeException('DB gone'));
        $this->logger->expects($this->once())->method('error');

        $result = $this->plugin->afterConvert($this->subject, $this->orderItem, $this->quoteItem);

        $this->assertFalse($result->hasData(Attributes::ORDER_ITEM_SNAPSHOT_COLUMN));
    }
}
