<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Garan;

use Magenx\GaranGraphQl\Model\Garan\DurationParser;
use Magenx\GaranGraphQl\Model\Garan\FieldFitChecker;
use Magenx\GaranGraphQl\Model\Garan\LabelValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LabelValidatorTest extends TestCase
{
    private const TERMS_URL = 'https://example.com/guarantee-terms';

    private FieldFitChecker&MockObject $fieldFitChecker;
    private LabelValidator $validator;

    protected function setUp(): void
    {
        $this->fieldFitChecker = $this->createMock(FieldFitChecker::class);
        $this->fieldFitChecker->method('fitsBrand')->willReturn(true);
        $this->fieldFitChecker->method('fitsModelIdentifier')->willReturn(true);
        $this->fieldFitChecker->method('fitsBrandAndModel')->willReturn(true);
        $this->fieldFitChecker->method('fitsDuration')->willReturn(true);
        $this->validator = new LabelValidator(new DurationParser(), $this->fieldFitChecker);
    }

    public function testReportsLegalDurationThatDoesNotFitTheLabel(): void
    {
        $checker = $this->createMock(FieldFitChecker::class);
        $checker->method('fitsBrand')->willReturn(true);
        $checker->method('fitsModelIdentifier')->willReturn(true);
        $checker->method('fitsBrandAndModel')->willReturn(true);
        $checker->method('fitsDuration')->willReturnMap([['4,5', false], ['7,5', true], ['5', true]]);
        $validator = new LabelValidator(new DurationParser(), $checker);

        $this->assertSame(
            [LabelValidator::REASON_DURATION_DOES_NOT_FIT],
            $validator->getViolations('Brand', 'Model', '4.500000', self::TERMS_URL)
        );
        $this->assertSame([], $validator->getViolations('Brand', 'Model', '7,5', self::TERMS_URL));
        $this->assertSame([], $validator->getViolations('Brand', 'Model', '5', self::TERMS_URL));
    }

    public function testDoesNotCheckFitOfInvalidOrMissingDuration(): void
    {
        $checker = $this->createMock(FieldFitChecker::class);
        $checker->method('fitsBrand')->willReturn(true);
        $checker->method('fitsModelIdentifier')->willReturn(true);
        $checker->method('fitsBrandAndModel')->willReturn(true);
        $checker->expects($this->never())->method('fitsDuration');
        $validator = new LabelValidator(new DurationParser(), $checker);

        $this->assertSame(
            [LabelValidator::REASON_INVALID_DURATION],
            $validator->getViolations('Brand', 'Model', '4,2', self::TERMS_URL)
        );
        $this->assertSame(
            [LabelValidator::REASON_MISSING_DURATION],
            $validator->getViolations('Brand', 'Model', null, self::TERMS_URL)
        );
    }

    public function testCompleteDataHasNoViolations(): void
    {
        $this->assertSame(
            [],
            $this->validator->getViolations(' Our Demo Brand ', 'Model XYZ 01', '4.500000', self::TERMS_URL)
        );
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('invalidData')]
    public function testReportsViolations(
        mixed $brand,
        mixed $model,
        mixed $duration,
        mixed $termsUrl,
        array $expected
    ): void {
        $this->assertSame($expected, $this->validator->getViolations($brand, $model, $duration, $termsUrl));
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: list<string>}>
     */
    public static function invalidData(): array
    {
        return [
            'nothing set' => [null, null, null, null, [
                LabelValidator::REASON_MISSING_BRAND,
                LabelValidator::REASON_MISSING_MODEL_IDENTIFIER,
                LabelValidator::REASON_MISSING_DURATION,
                LabelValidator::REASON_MISSING_TERMS_URL,
            ]],
            'blank brand' => ['  ', 'Model', '5', self::TERMS_URL, [LabelValidator::REASON_MISSING_BRAND]],
            'missing model' => ['Brand', '', '5', self::TERMS_URL, [LabelValidator::REASON_MISSING_MODEL_IDENTIFIER]],
            'missing duration' => ['Brand', 'Model', '', self::TERMS_URL, [LabelValidator::REASON_MISSING_DURATION]],
            'invalid duration from DB' => ['Brand', 'Model', '4.2', self::TERMS_URL, [
                LabelValidator::REASON_INVALID_DURATION,
            ]],
            'duration two years' => ['Brand', 'Model', '2', self::TERMS_URL, [LabelValidator::REASON_INVALID_DURATION]],
            'missing terms url' => ['Brand', 'Model', '5', null, [LabelValidator::REASON_MISSING_TERMS_URL]],
            'relative terms url' => ['Brand', 'Model', '5', '/terms', [LabelValidator::REASON_INVALID_TERMS_URL]],
            'ftp terms url' => ['Brand', 'Model', '5', 'ftp://example.com/terms', [
                LabelValidator::REASON_INVALID_TERMS_URL,
            ]],
            'javascript terms url' => ['Brand', 'Model', '5', 'javascript:alert(1)', [
                LabelValidator::REASON_INVALID_TERMS_URL,
            ]],
            'array brand' => [['Brand'], 'Model', '5', self::TERMS_URL, [LabelValidator::REASON_MISSING_BRAND]],
            'partial data' => ['Brand', null, '4,2', 'no url', [
                LabelValidator::REASON_MISSING_MODEL_IDENTIFIER,
                LabelValidator::REASON_INVALID_DURATION,
                LabelValidator::REASON_INVALID_TERMS_URL,
            ]],
        ];
    }

    public function testReportsTooLongBrand(): void
    {
        $checker = $this->createMock(FieldFitChecker::class);
        $checker->method('fitsBrand')->with('A very long brand')->willReturn(false);
        $checker->method('fitsDuration')->willReturn(true);
        $validator = new LabelValidator(new DurationParser(), $checker);

        $this->assertSame(
            [LabelValidator::REASON_TOO_LONG],
            $validator->getViolations('A very long brand', 'Model', '5', self::TERMS_URL)
        );
    }

    public function testTextFitsChecksCombinedWidthOnlyWhenBothAreSet(): void
    {
        $checker = $this->createMock(FieldFitChecker::class);
        $checker->method('fitsBrand')->willReturn(true);
        $checker->method('fitsModelIdentifier')->willReturn(true);
        $checker->expects($this->once())->method('fitsBrandAndModel')->with('Brand', 'Model')->willReturn(false);
        $validator = new LabelValidator(new DurationParser(), $checker);

        $this->assertTrue($validator->textFits('Brand', ''));
        $this->assertTrue($validator->textFits('', 'Model'));
        $this->assertFalse($validator->textFits('Brand', 'Model'));
    }

    public function testTextFitsRejectsTooLongModelIdentifier(): void
    {
        $checker = $this->createMock(FieldFitChecker::class);
        $checker->method('fitsModelIdentifier')->willReturn(false);
        $checker->expects($this->never())->method('fitsBrandAndModel');
        $validator = new LabelValidator(new DurationParser(), $checker);

        $this->assertFalse($validator->textFits('', 'Model XYZ 01 XBZ a42 and much more'));
    }

    #[DataProvider('termsUrls')]
    public function testIsValidTermsUrl(string $url, bool $expected): void
    {
        $this->assertSame($expected, $this->validator->isValidTermsUrl($url));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function termsUrls(): array
    {
        return [
            'https' => ['https://example.com/terms?lang=de', true],
            'http upper case scheme' => ['HTTP://example.com/terms', true],
            'no scheme' => ['example.com/terms', false],
            'mailto' => ['mailto:info@example.com', false],
            'empty' => ['', false],
            'spaces' => ['https://example.com/my terms', false],
        ];
    }

    public function testNormalizeText(): void
    {
        $this->assertSame('Brand', $this->validator->normalizeText(" Brand\n"));
        $this->assertSame('5', $this->validator->normalizeText(5));
        $this->assertSame('', $this->validator->normalizeText(null));
        $this->assertSame('', $this->validator->normalizeText(true));
        $this->assertSame('', $this->validator->normalizeText(['Brand']));
    }
}
