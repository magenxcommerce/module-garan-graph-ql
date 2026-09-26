<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Setup\Patch\Data;

use Magenx\GaranGraphQl\Model\Attribute\Backend\Duration;
use Magenx\GaranGraphQl\Model\Attribute\Backend\LabelText;
use Magenx\GaranGraphQl\Model\Attribute\Backend\TermsUrl;
use Magenx\GaranGraphQl\Model\Garan\Attributes;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Product attributes of the EU GARAN label in their own group of every product attribute set.
 */
class AddGaranProductAttributes implements DataPatchInterface, PatchRevertableInterface
{
    public const GROUP_NAME = 'EU GARAN Guarantee';

    private const GROUP_SORT_ORDER = 900;

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $eavSetup = $this->createEavSetup();
        foreach ($eavSetup->getAllAttributeSetIds(Product::ENTITY) as $attributeSetId) {
            $eavSetup->addAttributeGroup(Product::ENTITY, $attributeSetId, self::GROUP_NAME, self::GROUP_SORT_ORDER);
        }
        foreach ($this->getAttributeDefinitions() as $code => $definition) {
            $eavSetup->addAttribute(Product::ENTITY, $code, $definition);
        }
        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $eavSetup = $this->createEavSetup();
        foreach (Attributes::ALL as $code) {
            $eavSetup->removeAttribute(Product::ENTITY, $code);
        }
        foreach ($eavSetup->getAllAttributeSetIds(Product::ENTITY) as $attributeSetId) {
            // Look the group up first: removeAttributeGroup() falls back to the set's default group
            // if the name is unknown.
            $groupId = $eavSetup->getAttributeGroup(
                Product::ENTITY,
                $attributeSetId,
                self::GROUP_NAME,
                'attribute_group_id'
            );
            if (is_numeric($groupId)) {
                $eavSetup->removeAttributeGroup(Product::ENTITY, $attributeSetId, (int) $groupId);
            }
        }
        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAttributeDefinitions(): array
    {
        $common = [
            'input' => 'text',
            'group' => self::GROUP_NAME,
            'apply_to' => '',
            'visible' => true,
            'required' => false,
            'user_defined' => true,
            'unique' => false,
            'searchable' => false,
            'filterable' => false,
            'comparable' => false,
            'visible_on_front' => false,
            'visible_in_advanced_search' => false,
            'html_allowed_on_front' => false,
            'used_for_sort_by' => false,
            'used_for_promo_rules' => false,
            'used_in_product_listing' => false,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => false,
            'is_filterable_in_grid' => true,
        ];

        return [
            Attributes::BRAND => $common + [
                'type' => 'varchar',
                'label' => 'GARAN Brand',
                'backend' => LabelText::class,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'sort_order' => 10,
                'note' => 'Brand of the manufacturer guarantee as printed on the EU GARAN label.',
            ],
            Attributes::MODEL_IDENTIFIER => $common + [
                'type' => 'varchar',
                'label' => 'GARAN Model Identifier',
                'backend' => LabelText::class,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'sort_order' => 20,
                'note' => 'Model identifier as printed on the EU GARAN label.',
            ],
            Attributes::DURATION_YEARS => $common + [
                'type' => 'decimal',
                'label' => 'GARAN Guarantee Duration (Years)',
                'backend' => Duration::class,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'sort_order' => 30,
                'note' => 'Whole or half years, more than 2, e.g. 3 or 4,5. '
                    . 'Leave empty if there is no free manufacturer guarantee on the whole product.',
            ],
            Attributes::TERMS_URL => $common + [
                'type' => 'varchar',
                'label' => 'GARAN Guarantee Terms URL',
                'backend' => TermsUrl::class,
                'frontend_class' => 'validate-url',
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'sort_order' => 40,
                'note' => 'Full http:// or https:// URL of the manufacturer guarantee terms '
                    . 'in the store view language.',
            ],
        ];
    }

    private function createEavSetup(): EavSetup
    {
        return $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
    }
}
