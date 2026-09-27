<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Render;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- GD/FreeType drawing is what this class does; the framework has no wrapper for it.

use Magenx\GaranGraphQl\Api\Data\GaranLabelDataInterface;
use Magenx\GaranGraphQl\Model\Asset\MediaAssets;
use Magenx\GaranGraphQl\Model\Garan\FieldFitChecker;
use GdImage;
use InvalidArgumentException;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * PNG of the EU GARAN label for emails, cached in pub/media.
 *
 * The editable fields are drawn with GD/FreeType and the Inter TTFs onto a 4x raster of the official colour SVG
 * without its text elements, then scaled down to 2x (full label 539 x 567 px, nested 737 x 113 px).
 */
class GaranPngRenderer
{
    /**
     * Part of the cache key: raise when blanks, anchors or fonts change.
     */
    public const TEMPLATE_VERSION = '1';
    public const MEDIA_PATH = 'magenx_garan/garan';

    public const VARIANT_FULL = 'full';
    public const VARIANT_NESTED = 'nested';

    private const BLANKS = [
        self::VARIANT_FULL => 'label-blank@4x.png',
        self::VARIANT_NESTED => 'nested-blank@4x.png',
    ];

    /** Blank pixels per SVG unit */
    private const BLANK_SCALE = 4.0;
    private const OUTPUT_DIVISOR = 2;
    /** GD renders TrueType sizes in points at 96 dpi */
    private const PX_TO_PT = 0.75;
    /** Text colour of the official SVG (#231f20) */
    private const TEXT_RGB = [0x23, 0x1f, 0x20];

    public function __construct(
        private readonly FieldFitChecker $fieldFitChecker,
        private readonly MediaAssets $mediaAssets,
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Absolute media URL of the label PNG, or null if it cannot be provided. Never throws: emails must not break.
     */
    public function getUrl(
        GaranLabelDataInterface $label,
        string $variant = self::VARIANT_FULL,
        ?int $storeId = null
    ): ?string {
        try {
            $relativePath = $this->ensureFile($label, $variant);

            /** @var Store $store */
            $store = $this->storeManager->getStore($storeId);

            return $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, true) . $relativePath;
        } catch (Throwable $exception) {
            $this->logFailure($exception, $label, $variant);

            return null;
        }
    }

    /**
     * Contents of the label PNG, for attaching it to an email. Null if it cannot be provided; never throws.
     */
    public function getContents(
        GaranLabelDataInterface $label,
        string $variant = self::VARIANT_FULL,
        ?int $storeId = null
    ): ?string {
        try {
            $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $contents = $mediaDirectory->readFile($this->ensureFile($label, $variant));

            return $contents === '' ? null : $contents;
        } catch (Throwable $exception) {
            $this->logFailure($exception, $label, $variant);

            return null;
        }
    }

    /**
     * Media relative path of the label PNG, generated on first use.
     */
    private function ensureFile(GaranLabelDataInterface $label, string $variant): string
    {
        $relativePath = $this->getRelativePath($label, $variant);
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        if (!$mediaDirectory->isExist($relativePath)) {
            $this->generate($label, $variant, $mediaDirectory, $relativePath);
        }

        return $relativePath;
    }

    private function logFailure(Throwable $exception, GaranLabelDataInterface $label, string $variant): void
    {
        $this->logger->error(
            'Magenx_GaranGraphQl: GARAN label PNG could not be provided: ' . $exception->getMessage(),
            ['exception' => $exception, 'product_id' => $label->getProductId(), 'variant' => $variant]
        );
    }

    private function getRelativePath(GaranLabelDataInterface $label, string $variant): string
    {
        if (!isset(self::BLANKS[$variant])) {
            throw new InvalidArgumentException(sprintf('Unknown GARAN label variant "%s".', $variant));
        }

        $key = json_encode(
            [
                $variant,
                $label->getBrand(),
                $label->getModelIdentifier(),
                $label->getFormattedDuration(),
                self::TEMPLATE_VERSION,
            ],
            JSON_THROW_ON_ERROR
        );

        return self::MEDIA_PATH . '/' . sha1($key) . '.png';
    }

    private function generate(
        GaranLabelDataInterface $label,
        string $variant,
        WriteInterface $mediaDirectory,
        string $relativePath
    ): void {
        $blankPath = $this->mediaAssets->getAbsolutePath(MediaAssets::GARAN_DIRECTORY . '/' . self::BLANKS[$variant]);
        $image = imagecreatefrompng($blankPath);
        if ($image === false) {
            throw new RuntimeException(sprintf('Cannot read GARAN blank "%s".', $blankPath));
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        $color = imagecolorallocate($image, ...self::TEXT_RGB);
        if ($color === false) {
            throw new RuntimeException('Cannot allocate the GARAN text colour.');
        }

        if ($variant === self::VARIANT_FULL) {
            $this->drawText(
                $image,
                $color,
                $label->getBrand(),
                FieldFitChecker::FONT_REGULAR,
                FieldFitChecker::FIELD_FONT_SIZE,
                0.0,
                FieldFitChecker::FIELD_START_X,
                FieldFitChecker::FIELD_BASELINE_Y,
                false
            );
            $this->drawText(
                $image,
                $color,
                $label->getModelIdentifier(),
                FieldFitChecker::FONT_REGULAR,
                FieldFitChecker::FIELD_FONT_SIZE,
                0.0,
                FieldFitChecker::FIELD_END_X,
                FieldFitChecker::FIELD_BASELINE_Y,
                true
            );
        }

        $duration = $this->fieldFitChecker->getDurationLayout($variant);
        $this->drawText(
            $image,
            $color,
            $label->getFormattedDuration(),
            FieldFitChecker::FONT_EXTRA_BOLD,
            $duration['font_size'],
            $duration['letter_spacing'],
            $duration['anchor_x'],
            $duration['baseline_y'],
            true
        );

        $this->write($this->downscale($image), $mediaDirectory, $relativePath);
    }

    /**
     * Draws glyph by glyph at the positions of FieldFitChecker::layoutText(), so letter spacing, duration kerning
     * and the end anchor match the inline SVG.
     */
    private function drawText(
        GdImage $image,
        int $color,
        string $text,
        string $font,
        float $fontSize,
        float $letterSpacingEm,
        float $anchorX,
        float $baselineY,
        bool $alignEnd
    ): void {
        $layout = $this->fieldFitChecker->layoutText($text, $font, $fontSize, $letterSpacingEm);
        $startX = $alignEnd ? $anchorX - $layout['width'] : $anchorX;
        $fontPath = $this->fieldFitChecker->getFontPath($font);
        $y = (int) round($baselineY * self::BLANK_SCALE);

        foreach ($layout['glyphs'] as $glyph) {
            $drawn = imagettftext(
                $image,
                $fontSize * self::BLANK_SCALE * self::PX_TO_PT,
                0,
                (int) round(($startX + $glyph['x']) * self::BLANK_SCALE),
                $y,
                $color,
                $fontPath,
                $glyph['char']
            );
            if ($drawn === false) {
                throw new RuntimeException(sprintf('Cannot draw GARAN text with font "%s".', $font));
            }
        }
    }

    private function downscale(GdImage $image): GdImage
    {
        $width = intdiv(imagesx($image), self::OUTPUT_DIVISOR);
        $height = intdiv(imagesy($image), self::OUTPUT_DIVISOR);
        $output = imagecreatetruecolor($width, $height);
        if ($output === false) {
            throw new RuntimeException('Cannot create the GARAN output image.');
        }

        $copied = imagecopyresampled(
            $output,
            $image,
            0,
            0,
            0,
            0,
            $width,
            $height,
            $width * self::OUTPUT_DIVISOR,
            $height * self::OUTPUT_DIVISOR
        );
        if (!$copied) {
            throw new RuntimeException('Cannot scale the GARAN image.');
        }

        return $output;
    }

    /**
     * Writes to a unique temporary file and renames it, so concurrent requests never serve a partial PNG.
     *
     * @param GdImage $image
     * @param WriteInterface $mediaDirectory
     * @param string $relativePath
     * @return void
     */
    private function write(GdImage $image, WriteInterface $mediaDirectory, string $relativePath): void
    {
        $mediaDirectory->create(self::MEDIA_PATH);
        $temporaryPath = $relativePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if (!imagepng($image, $mediaDirectory->getAbsolutePath($temporaryPath), 9)) {
                throw new RuntimeException(sprintf('Cannot write GARAN PNG "%s".', $temporaryPath));
            }

            $mediaDirectory->renameFile($temporaryPath, $relativePath);
        } finally {
            // A failed write or rename must not leave partial files in pub/media.
            if ($mediaDirectory->isExist($temporaryPath)) {
                $mediaDirectory->delete($temporaryPath);
            }
        }
    }
}
