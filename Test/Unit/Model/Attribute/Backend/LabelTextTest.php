<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Attribute\Backend;

use Magenx\GaranGraphQl\Model\Attribute\Backend\LabelText;
use Magenx\GaranGraphQl\Model\Garan\Attributes;
use Magenx\GaranGraphQl\Model\Garan\DurationParser;
use Magenx\GaranGraphQl\Model\Garan\FieldFitChecker;
use Magenx\GaranGraphQl\Model\Garan\LabelValidator;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LabelTextTest extends TestCase
{
    private FieldFitChecker&MockObject $fieldFitChecker;

    protected function setUp(): void
    {
        $this->fieldFitChecker = $this->createMock(FieldFitChecker::class);
    }

    public function testBeforeSaveTrimsFittingBrand(): void
    {
        $this->fieldFitChecker->method('fitsBrand')->with('Our Demo Brand')->willReturn(true);
        $this->fieldFitChecker->expects($this->never())->method('fitsBrandAndModel');
        $product = new DataObject([Attributes::BRAND => '  Our Demo Brand ']);

        $this->createBackend(Attributes::BRAND)->beforeSave($product);

        $this->assertSame('Our Demo Brand', $product->getData(Attributes::BRAND));
    }

    public function testBeforeSaveStoresEmptyValueAsNull(): void
    {
        $this->fieldFitChecker->expects($this->never())->method('fitsModelIdentifier');
        $product = new DataObject([Attributes::MODEL_IDENTIFIER => ' ']);

        $this->createBackend(Attributes::MODEL_IDENTIFIER)->beforeSave($product);

        $this->assertNull($product->getData(Attributes::MODEL_IDENTIFIER));
    }

    public function testBeforeSaveRejectsTooLongBrand(): void
    {
        $this->fieldFitChecker->method('fitsBrand')->willReturn(false);
        $product = new DataObject([Attributes::BRAND => 'A brand name far too long for the label']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('too long for the label');

        $this->createBackend(Attributes::BRAND)->beforeSave($product);
    }

    public function testValidateRejectsModelThatDoesNotFitTogetherWithBrand(): void
    {
        $this->fieldFitChecker->method('fitsBrand')->willReturn(true);
        $this->fieldFitChecker->method('fitsModelIdentifier')->willReturn(true);
        $this->fieldFitChecker->expects($this->once())
            ->method('fitsBrandAndModel')
            ->with('Our Demo Brand Name', 'Model XYZ 01 XBZ a42')
            ->willReturn(false);
        $product = new DataObject([
            Attributes::BRAND => 'Our Demo Brand Name',
            Attributes::MODEL_IDENTIFIER => ' Model XYZ 01 XBZ a42 ',
        ]);

        $this->expectException(LocalizedException::class);

        $this->createBackend(Attributes::MODEL_IDENTIFIER)->validate($product);
    }

    public function testValidateAcceptsFittingBrandAndModel(): void
    {
        $this->fieldFitChecker->method('fitsBrand')->willReturn(true);
        $this->fieldFitChecker->method('fitsModelIdentifier')->willReturn(true);
        $this->fieldFitChecker->method('fitsBrandAndModel')->willReturn(true);
        $product = new DataObject([Attributes::BRAND => 'Brand', Attributes::MODEL_IDENTIFIER => 'Model']);

        $this->assertTrue($this->createBackend(Attributes::BRAND)->validate($product));
    }

    private function createBackend(string $attributeCode): LabelText
    {
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($attributeCode);
        $backend = new LabelText(new LabelValidator(new DurationParser(), $this->fieldFitChecker));
        $backend->setAttribute($attribute);

        return $backend;
    }
}
