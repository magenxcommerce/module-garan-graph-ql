<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Attribute\Backend;

use Magenx\GaranGraphQl\Model\Attribute\Backend\Duration;
use Magenx\GaranGraphQl\Model\Attribute\Backend\LabelText;
use Magenx\GaranGraphQl\Model\Attribute\Backend\TermsUrl;
use Magenx\GaranGraphQl\Model\Garan\DurationParser;
use Magenx\GaranGraphQl\Model\Garan\FieldFitChecker;
use Magenx\GaranGraphQl\Model\Garan\LabelValidator;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\DataObject;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResetsBackendStateTest extends TestCase
{
    /**
     * @return array<string, array{class-string<AbstractBackend>}>
     */
    public static function backends(): array
    {
        return [
            'duration' => [Duration::class],
            'label text' => [LabelText::class],
            'terms url' => [TermsUrl::class],
        ];
    }

    /**
     * @param class-string<AbstractBackend> $class
     */
    #[DataProvider('backends')]
    public function testResetStateRestoresTheConstructedState(string $class): void
    {
        $backend = $this->createBackend($class);
        $constructed = $this->lazyState($backend);
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getBackendTable')->willReturn('catalog_product_entity_varchar');
        $attribute->method('getEntityIdField')->willReturn('entity_id');
        $attribute->method('getDefaultValue')->willReturn('default');
        $backend->setAttribute($attribute);

        $backend->getTable();
        $backend->getEntityIdField();
        $backend->getDefaultValue();
        $backend->setValueId(7);
        $backend->setEntityValueId(new DataObject(['id' => 3]), 11);
        $this->assertNotSame($constructed, $this->lazyState($backend));

        $this->assertInstanceOf(ResetAfterRequestInterface::class, $backend);
        $backend->_resetState();

        $this->assertSame($constructed, $this->lazyState($backend));
        $this->assertSame('catalog_product_entity_varchar', $backend->getTable());
    }

    /**
     * @param class-string<AbstractBackend> $class
     */
    private function createBackend(string $class): AbstractBackend
    {
        $durationParser = new DurationParser();
        if ($class === Duration::class) {
            return new Duration($durationParser);
        }

        return new $class(new LabelValidator($durationParser, $this->createMock(FieldFitChecker::class)));
    }

    /**
     * @return array<string, mixed>
     */
    private function lazyState(AbstractBackend $backend): array
    {
        $state = [];
        foreach (['_table', '_entityIdField', '_valueId', '_valueIds', '_defaultValue'] as $property) {
            $state[$property] = (new \ReflectionProperty(AbstractBackend::class, $property))->getValue($backend);
        }

        return $state;
    }
}
