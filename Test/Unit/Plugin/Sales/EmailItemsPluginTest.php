<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Plugin\Sales;

use Magenx\GaranGraphQl\Api\Data\GaranLabelDataInterface;
use Magenx\GaranGraphQl\Api\GaranLabelResolverInterface;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Email\PendingAttachments;
use Magenx\GaranGraphQl\Model\Email\EmailAttachment;
use Magenx\GaranGraphQl\Model\Language\LanguageRegistry;
use Magenx\GaranGraphQl\Model\Render\GaranPngRenderer;
use Magenx\GaranGraphQl\Model\Render\NoticeRenderer;
use Magenx\GaranGraphQl\Plugin\Sales\EmailItemsPlugin;
use Magenx\GaranGraphQl\Model\Garan\LabelPresenter;
use Magento\Framework\Escaper;
use Magento\Sales\Block\Order\Email\Items;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class EmailItemsPluginTest extends TestCase
{
    private const STORE_ID = 5;
    private const ITEMS_HTML = '<table class="email-items"><tr><td>Items</td></tr></table>';
    private const PNG_URL = 'https://shop.test/static/frontend/Magenx_GaranGraphQl/notice/de.png';

    private Config&MockObject $config;
    private NoticeRenderer&MockObject $noticeRenderer;
    private GaranLabelResolverInterface&MockObject $resolver;
    private GaranPngRenderer&MockObject $pngRenderer;
    private LoggerInterface&MockObject $logger;
    private Items&MockObject $subject;
    private PendingAttachments $pendingAttachments;
    private EmailItemsPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getExcludedProductTypes')->willReturn(['virtual', 'mageworx_giftcards']);
        $this->noticeRenderer = $this->createMock(NoticeRenderer::class);
        $this->noticeRenderer->method('getPngUrl')->with(self::STORE_ID)->willReturn(self::PNG_URL);
        $this->noticeRenderer->method('getLinkUrl')->willReturn('https://europa.eu/youreurope/garantien');
        $this->noticeRenderer->method('getLinkLabel')->willReturn('europa.eu/youreurope/garantien');
        $this->noticeRenderer->method('getAltText')->willReturn('EU notice "legal" & guarantee');
        $this->resolver = $this->createMock(GaranLabelResolverInterface::class);
        $this->pngRenderer = $this->createMock(GaranPngRenderer::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = $this->createMock(Items::class);
        $this->pendingAttachments = new PendingAttachments();

        $escaper = $this->createMock(Escaper::class);
        $escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaper->method('escapeHtml')->willReturnCallback($escape);
        $escaper->method('escapeHtmlAttr')->willReturnCallback($escape);
        $escaper->method('escapeUrl')->willReturnCallback($escape);

        $this->plugin = new EmailItemsPlugin(
            $this->config,
            $this->noticeRenderer,
            $this->resolver,
            $this->pngRenderer,
            new LabelPresenter($this->pngRenderer),
            $this->pendingAttachments,
            $escaper,
            $this->logger
        );
    }

    public function testDisabledModuleLeavesEmailUnchanged(): void
    {
        $this->givenModes(false, false);
        $this->givenOrder([$this->createItem('simple')]);
        $this->resolver->expects($this->never())->method('forOrderItem');

        $this->assertSame(self::ITEMS_HTML, $this->plugin->afterToHtml($this->subject, self::ITEMS_HTML));
    }

    public function testMissingOrderLeavesEmailUnchanged(): void
    {
        $this->subject->method('getOrder')->willReturn(null);
        $this->config->expects($this->never())->method('isNoticeEmailInline');

        $this->assertSame(self::ITEMS_HTML, $this->plugin->afterToHtml($this->subject, self::ITEMS_HTML));
    }

    public function testNoticeIsAppendedBelowItems(): void
    {
        $this->givenModes(true, false);
        $this->givenOrder([$this->createItem('mageworx_giftcards'), $this->createItem('simple')]);

        $html = $this->plugin->afterToHtml($this->subject, self::ITEMS_HTML);

        $this->assertStringStartsWith(self::ITEMS_HTML, $html);
        $this->assertStringContainsString(
            '<a href="https://europa.eu/youreurope/garantien" style="text-decoration:none;"><img src="' . self::PNG_URL . '"',
            $html
        );
        $this->assertStringContainsString('width="560"', $html);
        $this->assertStringContainsString('style="width:100%;max-width:560px;height:auto;display:block"', $html);
        $this->assertStringContainsString('alt="EU notice &quot;legal&quot; &amp; guarantee"', $html);
        $this->assertStringContainsString(
            '<a href="https://europa.eu/youreurope/garantien">europa.eu/youreurope/garantien</a>',
            $html
        );
        $this->assertStringNotContainsString('<svg', $html);
    }

    public function testOrderWithExcludedTypesOnlyGetsNoNotice(): void
    {
        $this->givenModes(true, false);
        $this->givenOrder([$this->createItem('mageworx_giftcards'), $this->createItem('virtual')]);
        $this->noticeRenderer->expects($this->never())->method('getPngUrl');

        $this->assertSame(self::ITEMS_HTML, $this->plugin->afterToHtml($this->subject, self::ITEMS_HTML));
    }

    public function testGaranLabelsAreAppendedPerBundleChild(): void
    {
        $this->givenModes(false, true);
        $bundle = $this->createItem('bundle', 'Kitchen bundle');
        $kettle = $this->createLabel('Kettle', 'https://brand.test/terms?a=1&b=2');
        $toaster = $this->createLabel('Toaster', '');
        $this->givenOrder([$bundle]);
        $this->resolver->method('forOrderItem')->with($bundle)->willReturn([$kettle, $toaster]);
        $this->pngRenderer->method('getUrl')->willReturnMap([
            [$kettle, 'full', self::STORE_ID, 'https://shop.test/media/garan/kettle.png'],
            [$toaster, 'full', self::STORE_ID, 'https://shop.test/media/garan/toaster.png'],
        ]);

        $html = $this->plugin->afterToHtml($this->subject, self::ITEMS_HTML);

        $this->assertStringStartsWith(self::ITEMS_HTML, $html);
        $this->assertSame(2, substr_count($html, '<img '));
        $this->assertStringContainsString('Kitchen bundle', $html);
        $this->assertStringContainsString('<img src="https://shop.test/media/garan/kettle.png" width="270"', $html);
        $this->assertStringContainsString(
            'alt="GARAN – producer guarantee 5 years, Brand &amp; Co Model &lt;X&gt;"',
            $html
        );
        $this->assertSame(2, substr_count($html, 'href="' . LanguageRegistry::GARAN_INFO_URL . '"'));
        $this->assertStringContainsString('href="https://brand.test/terms?a=1&amp;b=2"', $html);
        $this->assertSame(1, substr_count($html, 'Guarantee terms and conditions'));
        $this->assertStringNotContainsString('<svg', $html);
    }

    public function testMissingPngKeepsAccessibleTextAndLinks(): void
    {
        $this->givenModes(false, true);
        $item = $this->createItem('simple', 'Kettle');
        $this->givenOrder([$item]);
        $this->resolver->method('forOrderItem')->willReturn([$this->createLabel('Kettle', 'https://brand.test/terms')]);
        $this->pngRenderer->method('getUrl')->willReturn(null);

        $html = $this->plugin->afterToHtml($this->subject, self::ITEMS_HTML);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('GARAN – producer guarantee 5 years, Brand &amp; Co Model &lt;X&gt;', $html);
        $this->assertStringContainsString('href="' . LanguageRegistry::GARAN_INFO_URL . '"', $html);
        $this->assertStringContainsString('href="https://brand.test/terms"', $html);
    }

    public function testItemsWithoutGaranLabelsAddNothing(): void
    {
        $this->givenModes(false, true);
        $this->givenOrder([$this->createItem('simple')]);
        $this->resolver->method('forOrderItem')->willReturn([]);

        $this->assertSame(self::ITEMS_HTML, $this->plugin->afterToHtml($this->subject, self::ITEMS_HTML));
    }

    public function testExceptionIsLoggedAndOriginalHtmlReturned(): void
    {
        $this->givenModes(true, true);
        $this->givenOrder([$this->createItem('simple')]);
        $this->resolver->method('forOrderItem')->willThrowException(new RuntimeException('snapshot broken'));
        $this->logger->expects($this->once())->method('error');

        $this->assertSame(self::ITEMS_HTML, $this->plugin->afterToHtml($this->subject, self::ITEMS_HTML));
    }

    public function testTermsNoteIsAppendedWhenADocumentIsPending(): void
    {
        $this->givenModes(false, true);
        $this->config->method('isTermsAttachmentEnabled')->with(self::STORE_ID)->willReturn(true);
        $this->pendingAttachments->add(new EmailAttachment('Garantiebedingungen.pdf', '%PDF', 'application/pdf'));
        $this->givenOrderWithLabel();

        $this->assertStringContainsString(
            'The guarantee terms of the producer are attached to this email.',
            $this->plugin->afterToHtml($this->subject, self::ITEMS_HTML)
        );
    }

    public function testTermsNoteIsOmittedWithoutAPendingDocument(): void
    {
        $this->givenModes(false, true);
        $this->config->method('isTermsAttachmentEnabled')->willReturn(true);
        $this->givenOrderWithLabel();

        $this->assertStringNotContainsString(
            'attached to this email',
            $this->plugin->afterToHtml($this->subject, self::ITEMS_HTML)
        );
    }

    public function testTermsNoteIsOmittedWhenTheAttachmentIsDisabled(): void
    {
        $this->givenModes(false, true);
        $this->config->method('isTermsAttachmentEnabled')->willReturn(false);
        $this->pendingAttachments->add(new EmailAttachment('Garantiebedingungen.pdf', '%PDF', 'application/pdf'));
        $this->givenOrderWithLabel();

        $this->assertStringNotContainsString(
            'attached to this email',
            $this->plugin->afterToHtml($this->subject, self::ITEMS_HTML)
        );
    }

    private function givenOrderWithLabel(): void
    {
        $item = $this->createItem('simple', 'Kettle');
        $this->givenOrder([$item]);
        $this->resolver->method('forOrderItem')->willReturn([$this->createLabel('Kettle', 'https://brand.test/terms')]);
        $this->pngRenderer->method('getUrl')->willReturn('https://shop.test/media/garan/kettle.png');
    }

    private function givenModes(bool $noticeInline, bool $garanInline): void
    {
        $this->config->method('isNoticeEmailInline')->with(self::STORE_ID)->willReturn($noticeInline);
        $this->config->method('isGaranEmailInline')->with(self::STORE_ID)->willReturn($garanInline);
    }

    /**
     * @param list<OrderItem> $items
     */
    private function givenOrder(array $items): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn((string) self::STORE_ID);
        $order->method('getAllVisibleItems')->willReturn($items);
        $this->subject->method('getOrder')->willReturn($order);
    }

    private function createItem(string $type, string $name = 'Item'): OrderItem&MockObject
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getProductType')->willReturn($type);
        $item->method('getName')->willReturn($name);

        return $item;
    }

    private function createLabel(string $productName, string $termsUrl): GaranLabelDataInterface&MockObject
    {
        $label = $this->createMock(GaranLabelDataInterface::class);
        $label->method('getProductName')->willReturn($productName);
        $label->method('getFormattedDuration')->willReturn('5');
        $label->method('getBrand')->willReturn('Brand & Co');
        $label->method('getModelIdentifier')->willReturn('Model <X>');
        $label->method('getTermsUrl')->willReturn($termsUrl);

        return $label;
    }
}
