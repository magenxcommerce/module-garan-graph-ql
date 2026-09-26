<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Garan;

use Magenx\GaranGraphQl\Model\Garan\GaranLabelData;
use PHPUnit\Framework\TestCase;

class GaranLabelDataTest extends TestCase
{
    /**
     * @dataProvider durationProvider
     */
    public function testDurationIsFormattedWithCommaAndWithoutTrailingZero(float $years, string $expected): void
    {
        self::assertSame($expected, $this->createLabel($years)->getFormattedDuration());
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function durationProvider(): array
    {
        return [
            'whole years' => [5.0, '5'],
            'two digits' => [10.0, '10'],
            'half year' => [4.5, '4,5'],
            'two and a half' => [2.5, '2,5'],
            'double digit half' => [12.5, '12,5'],
        ];
    }

    public function testGettersAndArrayExposeAllFields(): void
    {
        $label = $this->createLabel(3.0);

        self::assertSame(42, $label->getProductId());
        self::assertSame('Kaffeemaschine Deluxe', $label->getProductName());
        self::assertSame('KM-42', $label->getSku());
        self::assertSame('Acme & Söhne', $label->getBrand());
        self::assertSame('XYZ 01 XBZ a42', $label->getModelIdentifier());
        self::assertSame(3.0, $label->getDurationYears());
        self::assertSame('https://example.com/garantie', $label->getTermsUrl());
        self::assertSame(
            [
                'product_id' => 42,
                'product_name' => 'Kaffeemaschine Deluxe',
                'sku' => 'KM-42',
                'brand' => 'Acme & Söhne',
                'model_identifier' => 'XYZ 01 XBZ a42',
                'duration_years' => 3.0,
                'terms_url' => 'https://example.com/garantie',
            ],
            $label->toArray()
        );
    }

    private function createLabel(float $years): GaranLabelData
    {
        return new GaranLabelData(
            42,
            'Kaffeemaschine Deluxe',
            'KM-42',
            'Acme & Söhne',
            'XYZ 01 XBZ a42',
            $years,
            'https://example.com/garantie'
        );
    }
}
