<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Garan;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- imagettfbbox() is the font metric source; the framework has no wrapper for it.

use InvalidArgumentException;
use Magenx\GaranGraphQl\Model\Asset\MediaAssets;
use RuntimeException;

/**
 * Checks with the Inter 4.1 font metrics whether the editable GARAN fields fit the official label layout.
 *
 * All coordinates are SVG user units of the official files ("GARAN Label_colour.svg" 269.29 x 283.46,
 * "GARAN Label_nested display.svg" 368.5 x 56.69). Anchors were measured against the filled examples of the
 * EU practical guidelines (p. 23 and p. 29): the brand starts at the separator start, the model identifier
 * ends at the separator end and the duration digits end 5.9 units left of the calendar icon.
 */
class FieldFitChecker
{
    public const FONT_REGULAR = 'Inter-Regular.ttf';
    public const FONT_EXTRA_BOLD = 'Inter-ExtraBold.ttf';

    public const LAYOUT_FULL = 'full';
    public const LAYOUT_NESTED = 'nested';

    /**
     * Brand start: separator line start (x1 6.32); LL p.23/p.29 examples show the brand left-aligned there.
     */
    public const FIELD_START_X = 6.32;
    /**
     * Model identifier end (text-anchor="end"): separator line end (x2 262.97); the model identifiers of the LL
     * p.23/p.29 examples end there (measured ink end 262.4 / 262.6 plus the glyph side bearing).
     */
    public const FIELD_END_X = 262.97;
    public const FIELD_BASELINE_Y = 74.52;
    public const FIELD_FONT_SIZE = 9.0;
    /** Minimum space between brand and model identifier */
    public const FIELD_GAP = 8.0;
    /** Width shared by brand, gap and model identifier: 256.65 */
    public const FIELD_AVAILABLE_WIDTH = self::FIELD_END_X - self::FIELD_START_X;
    /**
     * Maximum advance width per field: 124.33. Half of the shared width minus the gap, so any two values accepted
     * separately also fit together. Measured: 14 x "W" and 57 x "i" fit, 15 x "W" and 58 x "i" do not.
     */
    public const MAX_FIELD_WIDTH = (self::FIELD_AVAILABLE_WIDTH - self::FIELD_GAP) / 2;

    /**
     * Duration end (text-anchor="end", letter spacing after the last character as in Chrome) in the full label.
     * The digits of LL p.23 ("5") and p.29 ("10") end their ink at x 115.6, i.e. 5.9 units left of the calendar
     * icon (x 121.47); Chrome renders the official SVG with the duration end-anchored here to exactly that ink end.
     */
    public const DURATION_END_X_FULL = 116.4;
    /** Leftmost allowed duration ink in the full label: separator start */
    public const DURATION_MIN_INK_X_FULL = 6.32;
    /** Ink width available for the duration in the full label: 115.6 - 6.32 = 109.28 */
    public const DURATION_AVAILABLE_INK_WIDTH_FULL = 115.6 - self::DURATION_MIN_INK_X_FULL;
    /**
     * Nested label equivalent (no filled example in the guidelines): same ink gap to the calendar icon (x 70.87)
     * and same digit side bearing scaled by 41.56/80, minus the -0.02em trailing letter spacing.
     */
    public const DURATION_END_X_NESTED = 68.65;
    /** "XX" origin 10.39 plus the full label margin (6.32 - 5.07) scaled by 41.56/80 */
    public const DURATION_MIN_INK_X_NESTED = 11.04;
    /** Ink width available for the duration in the nested label: 67.82 - 11.04 = 56.78 */
    public const DURATION_AVAILABLE_INK_WIDTH_NESTED = 67.82 - self::DURATION_MIN_INK_X_NESTED;

    /**
     * font_size in units, letter_spacing in em (applied after every character like browsers do), anchor_x is the
     * text-anchor="end" position, min_ink_x the leftmost allowed ink.
     */
    public const DURATION_LAYOUTS = [
        self::LAYOUT_FULL => [
            'font_size' => 80.0,
            'letter_spacing' => -0.03,
            'anchor_x' => self::DURATION_END_X_FULL,
            'baseline_y' => 150.57,
            'min_ink_x' => self::DURATION_MIN_INK_X_FULL,
        ],
        self::LAYOUT_NESTED => [
            'font_size' => 41.56,
            'letter_spacing' => -0.02,
            'anchor_x' => self::DURATION_END_X_NESTED,
            'baseline_y' => 46.65,
            'min_ink_x' => self::DURATION_MIN_INK_X_NESTED,
        ],
    ];

    /**
     * GD renders TrueType sizes in points at 96 dpi; measuring at a large size avoids hinting and rounding errors.
     */
    private const MEASURE_SIZE_PX = 1000.0;
    private const PX_TO_PT = 0.75;

    /**
     * GPOS pair kerning of Inter 4.1 ExtraBold for the duration characters (font units, 2048 per em). GD does not
     * apply GPOS kerning, browsers do; without it "7,5" would be measured 10 units wider than it is displayed.
     */
    private const UNITS_PER_EM = 2048;
    private const DURATION_KERNING = [
        '07' => -40, '0,' => -63, '24' => -32, '3,' => -41, '41' => -93, '4,' => -69, '5,' => -44, '6,' => -58,
        '70' => -32, '73' => -34, '74' => -119, '75' => -20, '76' => -32, '77' => 40, '78' => -29, '79' => -20,
        '7,' => -256, '8,' => -41, '97' => -40, '9,' => -63, ',0' => -63, ',1' => -188, ',3' => -44, ',5' => -7,
        ',6' => -63, ',7' => -62, ',8' => -63, ',9' => -31,
    ];

    /**
     * @var array<string, array<string, float>>
     */
    private array $advances = [];

    /**
     * @var array<string, array<string, float>>
     */
    private array $inkLefts = [];

    private ?string $fontDirectory = null;

    public function __construct(
        private readonly MediaAssets $mediaAssets
    ) {
    }

    public function fitsBrand(string $brand): bool
    {
        return $this->hasText($brand) && $this->getFieldWidth($brand) <= self::MAX_FIELD_WIDTH;
    }

    public function fitsModelIdentifier(string $modelIdentifier): bool
    {
        return $this->hasText($modelIdentifier) && $this->getFieldWidth($modelIdentifier) <= self::MAX_FIELD_WIDTH;
    }

    /**
     * Combined check on the real widths: brand, gap and model identifier within the separator line.
     */
    public function fitsBrandAndModel(string $brand, string $modelIdentifier): bool
    {
        if (!$this->hasText($brand) || !$this->hasText($modelIdentifier)) {
            return false;
        }

        $width = $this->getFieldWidth($brand) + self::FIELD_GAP + $this->getFieldWidth($modelIdentifier);

        return $width <= self::FIELD_END_X - self::FIELD_START_X;
    }

    /**
     * The duration must not cross the left margin in the full and the nested label. At the official size all
     * whole years up to 99 fit, of the half years only "7,5" (kerning "7,").
     */
    public function fitsDuration(string $formattedDuration): bool
    {
        if (!$this->hasText($formattedDuration)) {
            return false;
        }

        foreach (self::DURATION_LAYOUTS as $variant => $layout) {
            if ($this->getDurationInkLeft($formattedDuration, $variant) < $layout['min_ink_x']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Advance width of brand or model identifier in units (Inter Regular 9, no kerning, which is the wider case).
     */
    public function getFieldWidth(string $text): float
    {
        return $this->layoutText($text, self::FONT_REGULAR, self::FIELD_FONT_SIZE)['width'];
    }

    /**
     * Leftmost ink of the end-anchored duration in units.
     */
    public function getDurationInkLeft(string $formattedDuration, string $variant): float
    {
        $layout = $this->getDurationLayout($variant);
        $characters = $this->splitCharacters($formattedDuration);
        if ($characters === []) {
            return $layout['anchor_x'];
        }

        $width = $this->layoutText(
            $formattedDuration,
            self::FONT_EXTRA_BOLD,
            $layout['font_size'],
            $layout['letter_spacing']
        )['width'];

        return $layout['anchor_x'] - $width
            + $this->getInkLeft(self::FONT_EXTRA_BOLD, $characters[0]) * $layout['font_size'];
    }

    /**
     * @return array{font_size: float, letter_spacing: float, anchor_x: float, baseline_y: float, min_ink_x: float}
     */
    public function getDurationLayout(string $variant): array
    {
        if (!isset(self::DURATION_LAYOUTS[$variant])) {
            throw new InvalidArgumentException(sprintf('Unknown GARAN label variant "%s".', $variant));
        }

        return self::DURATION_LAYOUTS[$variant];
    }

    /**
     * Pen position of every character relative to the text start and the total advance in units. Letter spacing
     * follows every character including the last one, as in browsers, so an end anchor matches the SVG output.
     *
     * @return array{glyphs: list<array{char: string, x: float}>, width: float}
     */
    public function layoutText(string $text, string $font, float $fontSize, float $letterSpacingEm = 0.0): array
    {
        $x = 0.0;
        $glyphs = [];
        $previous = null;
        foreach ($this->splitCharacters($text) as $character) {
            if ($previous !== null) {
                $x += $this->getKerning($font, $previous . $character) * $fontSize;
            }
            $glyphs[] = ['char' => $character, 'x' => $x];
            $x += ($this->getAdvance($font, $character) + $letterSpacingEm) * $fontSize;
            $previous = $character;
        }

        return ['glyphs' => $glyphs, 'width' => $x];
    }

    public function getFontPath(string $font): string
    {
        if ($this->fontDirectory === null) {
            $this->fontDirectory = $this->mediaAssets->getAbsolutePath(MediaAssets::FONT_DIRECTORY) . '/';
        }

        return $this->fontDirectory . $font;
    }

    private function hasText(string $text): bool
    {
        return preg_match('//u', $text) === 1 && trim($text) !== '';
    }

    /**
     * @return list<string>
     */
    private function splitCharacters(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($characters) ? $characters : [];
    }

    /**
     * Advance in em: bbox("cH") - bbox("H") stays correct for glyphs whose ink exceeds their advance.
     */
    private function getAdvance(string $font, string $character): float
    {
        if (!isset($this->advances[$font][$character])) {
            $reference = $this->measure($font, 'H');
            $box = $this->measure($font, $character . 'H');
            $this->advances[$font][$character] = ($box[2] - $reference[2]) / self::MEASURE_SIZE_PX;
        }

        return $this->advances[$font][$character];
    }

    /**
     * Left side bearing of the glyph ink in em.
     */
    private function getInkLeft(string $font, string $character): float
    {
        if (!isset($this->inkLefts[$font][$character])) {
            $this->inkLefts[$font][$character] = $this->measure($font, $character)[0] / self::MEASURE_SIZE_PX;
        }

        return $this->inkLefts[$font][$character];
    }

    private function getKerning(string $font, string $pair): float
    {
        if ($font !== self::FONT_EXTRA_BOLD) {
            return 0.0;
        }

        return (self::DURATION_KERNING[$pair] ?? 0) / self::UNITS_PER_EM;
    }

    /**
     * @return array<int, int>
     */
    private function measure(string $font, string $text): array
    {
        $box = imagettfbbox(self::MEASURE_SIZE_PX * self::PX_TO_PT, 0, $this->getFontPath($font), $text);
        if ($box === false) {
            throw new RuntimeException(sprintf('Cannot measure text with font "%s".', $font));
        }

        return $box;
    }
}
