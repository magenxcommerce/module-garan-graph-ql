<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Asset;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Official EU artwork and the Inter fonts, deployed separately to pub/media/garan/{garan,fonts,notice}.
 *
 * The files are not shipped with the module, so they are served from the media URL like any other Magento
 * media and survive static content deploys untouched.
 */
class MediaAssets
{
    public const BASE_PATH = 'garan';
    public const NOTICE_DIRECTORY = 'notice';
    public const GARAN_DIRECTORY = 'garan';
    public const FONT_DIRECTORY = 'fonts/ttf';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Absolute secure media URL, e.g. "https://shop.test/media/garan/notice/de.svg".
     */
    public function getUrl(string $path, ?int $storeId = null): string
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore($storeId);

        return $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, true) . $this->getMediaPath($path);
    }

    /**
     * Absolute filesystem path, for reading the file on the server.
     */
    public function getAbsolutePath(string $path): string
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath($this->getMediaPath($path));
    }

    /**
     * Path relative to pub/media, e.g. "garan/notice/de.svg".
     */
    public function getMediaPath(string $path): string
    {
        return self::BASE_PATH . '/' . ltrim($path, '/');
    }
}
