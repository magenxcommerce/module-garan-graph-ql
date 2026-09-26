<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Setup\Patch\Data;

use Magenx\GaranGraphQl\Model\Attribute\Backend\Duration;
use Magenx\GaranGraphQl\Model\Attribute\Backend\LabelText;
use Magenx\GaranGraphQl\Model\Attribute\Backend\TermsUrl;
use Magenx\GaranGraphQl\Model\Garan\Attributes;
use Magenx\GaranGraphQl\Setup\Patch\Data\AddGaranProductAttributes;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AddGaranProductAttributesTest extends TestCase
{
    private EavSetup&MockObject $eavSetup;
    private AddGaranProductAttributes $patch;

    protected function setUp(): void
    {
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($this->createMock(AdapterInterface::class));
        $this->eavSetup = $this->createMock(EavSetup::class);
        $this->eavSetup->method('getAllAttributeSetIds')->with(Product::ENTITY)->willReturn(['4', '9']);
        $factory = $this->getMockBuilder(EavSetupFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->with(['setup' => $setup])->willReturn($this->eavSetup);

        $this->patch = new AddGaranProductAttributes($setup, $factory);
    }

    public function testApplyAddsGroupToAllSetsAndCreatesAttributes(): void
    {
        $groups = [];
        $this->eavSetup->expects($this->exactly(2))
            ->method('addAttributeGroup')
            ->willReturnCallback(function (string $entity, string $setId, string $name) use (&$groups): EavSetup {
                $groups[] = [$entity, $setId, $name];
                return $this->eavSetup;
            });
        $attributes = [];
        $this->eavSetup->expects($this->exactly(4))
            ->method('addAttribute')
            ->willReturnCallback(
                function (string $entity, string $code, array $definition) use (&$attributes): EavSetup {
                    $attributes[$code] = $definition;
                    return $this->eavSetup;
                }
            );

        $this->assertSame($this->patch, $this->patch->apply());

        $this->assertSame([
            [Product::ENTITY, '4', AddGaranProductAttributes::GROUP_NAME],
            [Product::ENTITY, '9', AddGaranProductAttributes::GROUP_NAME],
        ], $groups);
        $this->assertSame(Attributes::ALL, array_keys($attributes));
        $this->assertSame(LabelText::class, $attributes[Attributes::BRAND]['backend']);
        $this->assertSame(LabelText::class, $attributes[Attributes::MODEL_IDENTIFIER]['backend']);
        $this->assertSame(Duration::class, $attributes[Attributes::DURATION_YEARS]['backend']);
        $this->assertSame('decimal', $attributes[Attributes::DURATION_YEARS]['type']);
        $this->assertSame(TermsUrl::class, $attributes[Attributes::TERMS_URL]['backend']);
        $this->assertSame(ScopedAttributeInterface::SCOPE_STORE, $attributes[Attributes::TERMS_URL]['global']);
        foreach ($attributes as $definition) {
            // Empty means every product type: the fields are maintained on bundles and configurables too.
            $this->assertSame('', $definition['apply_to']);
            $this->assertSame(ScopedAttributeInterface::SCOPE_STORE, $definition['global']);
            $this->assertSame(AddGaranProductAttributes::GROUP_NAME, $definition['group']);
            $this->assertTrue($definition['user_defined']);
            $this->assertFalse($definition['required']);
            $this->assertFalse($definition['used_in_product_listing']);
            $this->assertTrue($definition['is_used_in_grid']);
            $this->assertTrue($definition['is_filterable_in_grid']);
            $this->assertFalse($definition['visible_on_front']);
            $this->assertFalse($definition['searchable']);
        }
    }

    public function testRevertRemovesAttributesAndOnlyExistingGroups(): void
    {
        $removed = [];
        $this->eavSetup->expects($this->exactly(4))
            ->method('removeAttribute')
            ->willReturnCallback(function (string $entity, string $code) use (&$removed): EavSetup {
                $removed[] = $code;
                return $this->eavSetup;
            });
        $this->eavSetup->method('getAttributeGroup')->willReturnMap([
            [Product::ENTITY, '4', AddGaranProductAttributes::GROUP_NAME, 'attribute_group_id', '77'],
            [Product::ENTITY, '9', AddGaranProductAttributes::GROUP_NAME, 'attribute_group_id', false],
        ]);
        $this->eavSetup->expects($this->once())
            ->method('removeAttributeGroup')
            ->with(Product::ENTITY, '4', 77);

        $this->patch->revert();

        $this->assertSame(Attributes::ALL, $removed);
    }

    public function testHasNoDependenciesOrAliases(): void
    {
        $this->assertSame([], AddGaranProductAttributes::getDependencies());
        $this->assertSame([], $this->patch->getAliases());
    }
}
