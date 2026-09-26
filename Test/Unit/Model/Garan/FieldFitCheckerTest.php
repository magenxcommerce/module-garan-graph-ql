<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Garan;

use Magenx\GaranGraphQl\Model\Garan\FieldFitChecker;
use InvalidArgumentException;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use PHPUnit\Framework\TestCase;

/**
 * Uses the real Inter TTFs of the module, so the boundaries below are the measured limits of the official layout.
 */
class FieldFitCheckerTest extends TestCase
{
    private FieldFitChecker $checker;

    protected function setUp(): void
    {
        $moduleDirReader = $this->createMock(ModuleDirReader::class);
        $moduleDirReader->method('getModuleDir')
            ->with('view', 'Magenx_GaranGraphQl')
            ->willReturn(dirname(__DIR__, 4) . '/view');
        $this->checker = new FieldFitChecker($moduleDirReader);
    }

    public function testGuidelineExampleValuesFit(): void
    {
        $this->assertTrue($this->checker->fitsBrand('Our Demo Brand Name'));
        $this->assertTrue($this->checker->fitsModelIdentifier('Model XYZ 01 XBZ a42'));
        $this->assertTrue($this->checker->fitsBrandAndModel('Our Demo Brand Name', 'Model XYZ 01 XBZ a42'));
    }

    public function testVeryLongValuesDoNotFit(): void
    {
        $long = str_repeat('Brand ', 10);

        $this->assertFalse($this->checker->fitsBrand($long));
        $this->assertFalse($this->checker->fitsModelIdentifier($long));
        $this->assertFalse($this->checker->fitsBrandAndModel($long, 'Model'));
    }

    public function testFieldLimitIsMetricNotCharacterCount(): void
    {
        $this->assertTrue($this->checker->fitsBrand(str_repeat('W', 14)));
        $this->assertFalse($this->checker->fitsBrand(str_repeat('W', 15)));
        $this->assertTrue($this->checker->fitsModelIdentifier(str_repeat('i', 57)));
        $this->assertFalse($this->checker->fitsModelIdentifier(str_repeat('i', 58)));
    }

    public function testFieldWidthMatchesOfficialPlaceholderWidth(): void
    {
        // "Model identifier" in the official SVG measures 66.53 units in the browser.
        $this->assertEqualsWithDelta(66.53, $this->checker->getFieldWidth('Model identifier'), 0.3);
        $this->assertEqualsWithDelta(124.325, FieldFitChecker::MAX_FIELD_WIDTH, 0.001);
    }

    public function testNamedAnchorsMatchMeasuredLayout(): void
    {
        $this->assertEqualsWithDelta(256.65, FieldFitChecker::FIELD_AVAILABLE_WIDTH, 0.001);
        $this->assertEqualsWithDelta(109.28, FieldFitChecker::DURATION_AVAILABLE_INK_WIDTH_FULL, 0.001);
        $this->assertEqualsWithDelta(56.78, FieldFitChecker::DURATION_AVAILABLE_INK_WIDTH_NESTED, 0.001);
        $this->assertSame(
            FieldFitChecker::DURATION_END_X_FULL,
            $this->checker->getDurationLayout(FieldFitChecker::LAYOUT_FULL)['anchor_x']
        );
        $this->assertSame(
            FieldFitChecker::DURATION_END_X_NESTED,
            $this->checker->getDurationLayout(FieldFitChecker::LAYOUT_NESTED)['anchor_x']
        );
    }

    public function testCombinedCheckUsesRealWidthsOfBothFields(): void
    {
        $longBrand = 'Our Demo Brand Name Extended';

        $this->assertFalse($this->checker->fitsBrand($longBrand));
        $this->assertTrue($this->checker->fitsBrandAndModel($longBrand, 'Model XYZ 01 XBZ a42'));
        $this->assertTrue($this->checker->fitsBrandAndModel(str_repeat('W', 14), str_repeat('W', 14)));
        $this->assertFalse($this->checker->fitsBrandAndModel(str_repeat('W', 15), str_repeat('W', 14)));
    }

    /**
     * @dataProvider durationProvider
     */
    public function testDurationFit(string $duration, bool $expected): void
    {
        $this->assertSame($expected, $this->checker->fitsDuration($duration));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function durationProvider(): array
    {
        return [
            'smallest qualifying whole year' => ['3', true],
            'guideline example 5' => ['5', true],
            'guideline example 10' => ['10', true],
            'widest two digits' => ['40', true],
            'maximum' => ['99', true],
            'half year fitting through kerning' => ['7,5', true],
            'half year 2,5 crosses the margin' => ['2,5', false],
            'half year 4,5 crosses the margin' => ['4,5', false],
            'half year 5,5 crosses the margin' => ['5,5', false],
            'half year 9,5 crosses the margin' => ['9,5', false],
            'three digits' => ['100', false],
            'two digit half year' => ['12,5', false],
        ];
    }

    public function testDurationInkEdgesMatchBrowserMeasurement(): void
    {
        // Chrome rendering of the template: "40" starts at 14.4 and "7,5" at 10.4 units.
        $this->assertEqualsWithDelta(14.4, $this->checker->getDurationInkLeft('40', 'full'), 0.3);
        $this->assertEqualsWithDelta(10.4, $this->checker->getDurationInkLeft('7,5', 'full'), 0.3);
    }

    public function testLayoutAppliesLetterSpacingAfterEveryCharacterAndDurationKerning(): void
    {
        $five = $this->checker->layoutText('5', FieldFitChecker::FONT_EXTRA_BOLD, 80.0, -0.03);
        $this->assertEqualsWithDelta(50.7 - 2.4, $five['width'], 0.1);

        $sevenComma = $this->checker->layoutText('7,', FieldFitChecker::FONT_EXTRA_BOLD, 80.0);
        $seven = $this->checker->layoutText('7', FieldFitChecker::FONT_EXTRA_BOLD, 80.0);
        $comma = $this->checker->layoutText(',', FieldFitChecker::FONT_EXTRA_BOLD, 80.0);
        $this->assertEqualsWithDelta(
            $seven['width'] + $comma['width'] - 10.0,
            $sevenComma['width'],
            0.1
        );
        $this->assertEqualsWithDelta($seven['width'] - 10.0, $sevenComma['glyphs'][1]['x'], 0.1);
    }

    public function testEmptyAndInvalidValuesDoNotFit(): void
    {
        $this->assertFalse($this->checker->fitsBrand(''));
        $this->assertFalse($this->checker->fitsBrand('   '));
        $this->assertFalse($this->checker->fitsModelIdentifier(''));
        $this->assertFalse($this->checker->fitsModelIdentifier("\xff\xfe"));
        $this->assertFalse($this->checker->fitsDuration(''));
        $this->assertFalse($this->checker->fitsBrandAndModel('', 'Model'));
        $this->assertFalse($this->checker->fitsBrandAndModel('Brand', ''));
    }

    public function testUnknownLayoutThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->checker->getDurationLayout('huge');
    }
}
