<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Asset;

use Magenx\GaranGraphQl\Model\Asset\MediaAssets;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class MediaAssetsTest extends TestCase
{
    public function testUrlIsSecureMediaUrlOfStore(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->with('media', true)->willReturn('https://shop.test/media/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->once())->method('getStore')->with(2)->willReturn($store);

        $assets = new MediaAssets($this->createMock(Filesystem::class), $storeManager);

        $this->assertSame('https://shop.test/media/garan/notice/de.svg', $assets->getUrl('notice/de.svg', 2));
    }

    public function testAbsolutePathIsBelowMediaDirectory(): void
    {
        $media = $this->createMock(ReadInterface::class);
        $media->method('getAbsolutePath')->willReturnCallback(
            static fn (string $path): string => '/var/www/pub/media/' . $path
        );
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->with('media')->willReturn($media);

        $assets = new MediaAssets($filesystem, $this->createMock(StoreManagerInterface::class));

        $this->assertSame('/var/www/pub/media/garan/fonts/ttf', $assets->getAbsolutePath('fonts/ttf'));
        $this->assertSame('garan/garan/label-blank@4x.png', $assets->getMediaPath('/garan/label-blank@4x.png'));
    }
}
