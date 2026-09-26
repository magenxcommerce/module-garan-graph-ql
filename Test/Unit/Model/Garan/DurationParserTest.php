<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Garan;

use Magenx\GaranGraphQl\Model\Garan\DurationParser;
use Magenx\GaranGraphQl\Model\Garan\GaranLabelData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DurationParserTest extends TestCase
{
    private DurationParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DurationParser();
    }

    #[DataProvider('validDurations')]
    public function testParsesValidDuration(mixed $raw, float $expected): void
    {
        $this->assertSame($expected, $this->parser->parse($raw));
    }

    #[DataProvider('invalidDurations')]
    public function testRejectsInvalidDuration(mixed $raw): void
    {
        $this->assertNull($this->parser->parse($raw));
    }

    #[DataProvider('formattedDurations')]
    public function testFormatMatchesLabelData(float $years, string $expected): void
    {
        $label = new GaranLabelData(1, 'Name', 'SKU', 'Brand', 'Model', $years, 'https://example.com');

        $this->assertSame($expected, $this->parser->format($years));
        $this->assertSame($label->getFormattedDuration(), $this->parser->format($years));
    }

    /**
     * @return array<string, array{0: float, 1: string}>
     */
    public static function formattedDurations(): array
    {
        return [
            'whole years' => [3.0, '3'],
            'two digits' => [99.0, '99'],
            'half year' => [4.5, '4,5'],
            'half year two digits' => [98.5, '98,5'],
        ];
    }

    /**
     * @return array<string, array{0: mixed, 1: float}>
     */
    public static function validDurations(): array
    {
        return [
            'half year above minimum, comma' => ['2,5', 2.5],
            'whole years' => ['3', 3.0],
            'decimal point' => ['4.5', 4.5],
            'decimal comma' => ['4,5', 4.5],
            'two digits' => ['10', 10.0],
            'maximum' => ['99', 99.0],
            'surrounding whitespace' => [' 3 ', 3.0],
            'float' => [4.5, 4.5],
            'integer' => [5, 5.0],
            'stored EAV decimal' => ['4.500000', 4.5],
            'stored EAV decimal whole' => ['99.000000', 99.0],
            'trailing zero' => ['3,0', 3.0],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidDurations(): array
    {
        return [
            'legal minimum is not more than 2' => ['2'],
            'below minimum' => ['1,5'],
            'other decimal' => ['4,2'],
            'other decimal with point' => ['4.2'],
            'quarter year' => ['4,25'],
            'zero' => ['0'],
            'above maximum' => ['100'],
            'half year above maximum' => ['99,5'],
            'empty' => [''],
            'whitespace only' => ['   '],
            'text' => ['abc'],
            'null' => [null],
            'two separators' => ['4,5,5'],
            'negative' => ['-3'],
            'trailing separator' => ['3,'],
            'exponent' => ['1e1'],
            'integer two' => [2],
            'float out of step' => [4.2],
            'boolean' => [true],
            'array' => [['4,5']],
            'stored decimal out of step' => ['4.200000'],
            'infinite' => [INF],
        ];
    }
}
