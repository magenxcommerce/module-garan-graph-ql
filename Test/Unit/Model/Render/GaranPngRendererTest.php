<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Render;

use Magenx\GaranGraphQl\Model\Garan\FieldFitChecker;
use Magenx\GaranGraphQl\Model\Garan\GaranLabelData;
use Magenx\GaranGraphQl\Model\Render\GaranPngRenderer;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Renders with real GD and the module fonts into a temporary media directory.
 */
class GaranPngRendererTest extends TestCase
{
    private const MEDIA_URL = 'https://shop.test/media/';

    private string $mediaRoot;
    private WriteInterface&MockObject $mediaDirectory;
    private StoreManagerInterface&MockObject $storeManager;
    private LoggerInterface&MockObject $logger;
    private GaranPngRenderer $renderer;

    protected function setUp(): void
    {
        $this->mediaRoot = sys_get_temp_dir() . '/magenx-garan-' . bin2hex(random_bytes(6));
        mkdir($this->mediaRoot);

        $this->mediaDirectory = $this->createMock(WriteInterface::class);
        $this->mediaDirectory->method('isExist')->willReturnCallback(
            fn (string $path): bool => file_exists($this->mediaRoot . '/' . $path)
        );
        $this->mediaDirectory->method('getAbsolutePath')->willReturnCallback(
            fn (string $path): string => $this->mediaRoot . '/' . $path
        );
        $this->mediaDirectory->method('create')->willReturnCallback(
            fn (string $path): bool => is_dir($this->mediaRoot . '/' . $path)
                || mkdir($this->mediaRoot . '/' . $path, 0777, true)
        );
        $this->mediaDirectory->method('renameFile')->willReturnCallback(
            fn (string $from, string $to): bool => rename($this->mediaRoot . '/' . $from, $this->mediaRoot . '/' . $to)
        );
        $this->mediaDirectory->method('delete')->willReturnCallback(
            fn (string $path): bool => unlink($this->mediaRoot . '/' . $path)
        );

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->with('media', true)->willReturn(self::MEDIA_URL);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->renderer = $this->createRenderer($this->mediaDirectory);
    }

    protected function tearDown(): void
    {
        $files = glob($this->mediaRoot . '/' . GaranPngRenderer::MEDIA_PATH . '/*') ?: [];
        array_map('unlink', $files);
        foreach ([GaranPngRenderer::MEDIA_PATH, 'magenx_garan', ''] as $directory) {
            $path = rtrim($this->mediaRoot . '/' . $directory, '/');
            if (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    public function testFirstCallCreatesTwoTimesPngWithFilledFields(): void
    {
        $this->logger->expects($this->never())->method('error');
        $label = $this->createLabel('Our Demo Brand Name', 'Model XYZ 01 XBZ a42', 5.0);

        $url = $this->renderer->getUrl($label, GaranPngRenderer::VARIANT_FULL, 1);

        $this->assertMatchesRegularExpression(
            '#^https://shop\.test/media/magenx_garan/garan/[0-9a-f]{40}\.png$#',
            (string) $url
        );
        $file = $this->mediaRoot . '/' . substr((string) $url, strlen(self::MEDIA_URL));
        $this->assertSame([539, 567], array_slice(getimagesize($file) ?: [], 0, 2));

        $image = imagecreatefrompng($file);
        // 2 px per unit: brand, model identifier and duration regions contain text ink, the official placeholders
        // "XX" left of the "5" do not.
        $this->assertGreaterThan(50, $this->countDarkPixels($image, 14, 136, 110, 150));
        $this->assertGreaterThan(50, $this->countDarkPixels($image, 420, 136, 525, 150));
        $this->assertGreaterThan(500, $this->countDarkPixels($image, 145, 190, 232, 300));
        $this->assertSame(0, $this->countDarkPixels($image, 20, 190, 130, 300));
        $this->assertSame([], glob(dirname($file) . '/*.tmp') ?: []);
    }

    public function testNestedVariantHasItsOwnFileAndSize(): void
    {
        $label = $this->createLabel('Acme', 'K-100', 10.0);

        $fullUrl = $this->renderer->getUrl($label);
        $nestedUrl = $this->renderer->getUrl($label, GaranPngRenderer::VARIANT_NESTED);

        $this->assertNotNull($nestedUrl);
        $this->assertNotSame($fullUrl, $nestedUrl);
        $file = $this->mediaRoot . '/' . substr($nestedUrl, strlen(self::MEDIA_URL));
        $this->assertSame([737, 113], array_slice(getimagesize($file) ?: [], 0, 2));
    }

    public function testCacheHitReturnsUrlWithoutRegenerating(): void
    {
        $label = $this->createLabel('Acme', 'K-100', 5.0);
        $url = $this->renderer->getUrl($label);
        $this->assertNotNull($url);

        $mediaDirectory = $this->createMock(WriteInterface::class);
        $mediaDirectory->expects($this->once())
            ->method('isExist')
            ->with(substr($url, strlen(self::MEDIA_URL)))
            ->willReturn(true);
        $mediaDirectory->expects($this->never())->method('create');
        $mediaDirectory->expects($this->never())->method('getAbsolutePath');
        $mediaDirectory->expects($this->never())->method('renameFile');

        $this->assertSame($url, $this->createRenderer($mediaDirectory)->getUrl($label));
    }

    public function testCacheKeyChangesWithEveryField(): void
    {
        $urls = [
            $this->renderer->getUrl($this->createLabel('Acme', 'K-100', 5.0)),
            $this->renderer->getUrl($this->createLabel('Acme', 'K-100', 6.0)),
            $this->renderer->getUrl($this->createLabel('Acme', 'K-101', 5.0)),
            $this->renderer->getUrl($this->createLabel('Acme2', 'K-100', 5.0)),
        ];

        $this->assertNotContains(null, $urls);
        $this->assertCount(4, array_unique($urls));
    }

    public function testWriteFailureReturnsNullAndLogs(): void
    {
        $mediaDirectory = $this->createMock(WriteInterface::class);
        $mediaDirectory->method('isExist')->willReturn(false);
        $mediaDirectory->method('create')->willThrowException(new FileSystemException(__('Permission denied')));
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Permission denied'), $this->arrayHasKey('exception'));

        $this->assertNull($this->createRenderer($mediaDirectory)->getUrl($this->createLabel('Acme', 'K-100', 5.0)));
    }

    public function testFailedRenameRemovesTemporaryFile(): void
    {
        $mediaDirectory = $this->createMock(WriteInterface::class);
        $mediaDirectory->method('isExist')->willReturnCallback(
            fn (string $path): bool => file_exists($this->mediaRoot . '/' . $path)
        );
        $mediaDirectory->method('getAbsolutePath')->willReturnCallback(
            fn (string $path): string => $this->mediaRoot . '/' . $path
        );
        $mediaDirectory->method('create')->willReturnCallback(
            fn (string $path): bool => is_dir($this->mediaRoot . '/' . $path)
                || mkdir($this->mediaRoot . '/' . $path, 0777, true)
        );
        $mediaDirectory->method('renameFile')->willThrowException(new FileSystemException(__('Rename failed')));
        $mediaDirectory->expects($this->once())
            ->method('delete')
            ->with($this->stringEndsWith('.tmp'))
            ->willReturnCallback(fn (string $path): bool => unlink($this->mediaRoot . '/' . $path));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('Rename failed'));

        $this->assertNull($this->createRenderer($mediaDirectory)->getUrl($this->createLabel('Acme', 'K-100', 5.0)));
        $this->assertSame([], glob($this->mediaRoot . '/' . GaranPngRenderer::MEDIA_PATH . '/*') ?: []);
    }

    public function testUnknownStoreReturnsNullAndLogs(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willThrowException(new NoSuchEntityException(__('No such store')));
        $this->logger->expects($this->once())->method('error');

        $this->assertNull($this->createRenderer($this->mediaDirectory)->getUrl(
            $this->createLabel('Acme', 'K-100', 5.0),
            GaranPngRenderer::VARIANT_FULL,
            999
        ));
    }

    public function testUnknownVariantReturnsNullAndLogs(): void
    {
        $this->logger->expects($this->once())->method('error');

        $this->assertNull($this->renderer->getUrl($this->createLabel('Acme', 'K-100', 5.0), 'poster'));
    }

    private function createRenderer(WriteInterface $mediaDirectory): GaranPngRenderer
    {
        $moduleDirReader = $this->createMock(ModuleDirReader::class);
        $moduleDirReader->method('getModuleDir')->willReturn(dirname(__DIR__, 4) . '/view');
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->with('media')->willReturn($mediaDirectory);

        return new GaranPngRenderer(
            new FieldFitChecker($moduleDirReader),
            $moduleDirReader,
            $filesystem,
            $this->storeManager,
            $this->logger
        );
    }

    private function createLabel(string $brand, string $modelIdentifier, float $years): GaranLabelData
    {
        return new GaranLabelData(42, 'Kettle', 'KET-1', $brand, $modelIdentifier, $years, 'https://example.com/terms');
    }

    private function countDarkPixels(\GdImage $image, int $x0, int $y0, int $x1, int $y1): int
    {
        $count = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                if ((imagecolorat($image, $x, $y) & 0xFF) < 128) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
