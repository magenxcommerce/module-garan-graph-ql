<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Attribute\Backend;

use Magenx\GaranGraphQl\Model\Attribute\Backend\Duration;
use Magenx\GaranGraphQl\Model\Garan\Attributes;
use Magenx\GaranGraphQl\Model\Garan\DurationParser;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class DurationTest extends TestCase
{
    private Duration $backend;

    protected function setUp(): void
    {
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn(Attributes::DURATION_YEARS);
        $this->backend = new Duration(new DurationParser());
        $this->backend->setAttribute($attribute);
    }

    public function testBeforeSaveStoresLocaleInputAsFloat(): void
    {
        $product = new DataObject([Attributes::DURATION_YEARS => '4,5']);

        $this->backend->beforeSave($product);

        $this->assertSame(4.5, $product->getData(Attributes::DURATION_YEARS));
    }

    public function testBeforeSaveStoresEmptyInputAsNull(): void
    {
        $product = new DataObject([Attributes::DURATION_YEARS => '  ']);

        $this->backend->beforeSave($product);

        $this->assertNull($product->getData(Attributes::DURATION_YEARS));
    }

    public function testBeforeSaveLeavesProductWithoutValueUntouched(): void
    {
        $product = new DataObject(['sku' => 'abc']);

        $this->backend->beforeSave($product);

        $this->assertFalse($product->hasData(Attributes::DURATION_YEARS));
    }

    public function testBeforeSaveRejectsInvalidDuration(): void
    {
        $product = new DataObject([Attributes::DURATION_YEARS => '4,2']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The GARAN guarantee duration "4,2" is invalid.');

        $this->backend->beforeSave($product);
    }

    public function testValidateAcceptsValidAndEmptyDuration(): void
    {
        $this->assertTrue($this->backend->validate(new DataObject([Attributes::DURATION_YEARS => '3'])));
        $this->assertTrue($this->backend->validate(new DataObject([Attributes::DURATION_YEARS => null])));
    }

    public function testValidateRejectsTwoYears(): void
    {
        $this->expectException(LocalizedException::class);

        $this->backend->validate(new DataObject([Attributes::DURATION_YEARS => '2']));
    }
}
