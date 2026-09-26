<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Garan;

use Magenx\GaranGraphQl\Api\Data\GaranLabelDataInterface;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Garan\Attributes;
use Magenx\GaranGraphQl\Model\Garan\DurationParser;
use Magenx\GaranGraphQl\Model\Garan\FieldFitChecker;
use Magenx\GaranGraphQl\Model\Garan\GaranLabelData;
use Magenx\GaranGraphQl\Model\Garan\GaranLabelDataFactory;
use Magenx\GaranGraphQl\Model\Garan\LabelValidator;
use Magenx\GaranGraphQl\Model\Garan\PrefetchedValues;
use Magenx\GaranGraphQl\Model\Garan\Resolver;
use Magenx\GaranGraphQl\Model\Source\BrandSource;
use Magenx\GaranGraphQl\Model\Source\ModelIdentifierSource;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResolverTest extends TestCase
{
    private const STORE_ID = 3;
    private const TERMS_URL = 'https://example.com/terms';

    private Config&MockObject $config;
    private FieldFitChecker&MockObject $fieldFitChecker;
    private ProductResource&MockObject $productResource;
    private LoggerInterface&MockObject $logger;
    private ConfigurableResource&MockObject $configurableResource;
    private Resolver $resolver;

    /**
     * @var array<int, list<int>>
     */
    private array $parentIdsByChild = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->configurableResource = $this->createMock(ConfigurableResource::class);
        $this->configurableResource->method('getParentIdsByChild')->willReturnCallback(
            fn (mixed $childId): array => $this->parentIdsByChild[(int) $childId] ?? []
        );
        $this->config->method('getExcludedProductTypes')->willReturn(['mageworx_giftcards']);
        $this->fieldFitChecker = $this->createMock(FieldFitChecker::class);
        $this->productResource = $this->createMock(ProductResource::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $factory = $this->getMockBuilder(GaranLabelDataFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(
            static fn (array $data): GaranLabelData => new GaranLabelData(...$data)
        );

        $durationParser = new DurationParser();
        $this->resolver = new Resolver(
            $this->config,
            new LabelValidator($durationParser, $this->fieldFitChecker),
            $durationParser,
            $this->productResource,
            $this->configurableResource,
            $factory,
            new Json(),
            $this->logger
        );
    }

    public function testForProductReturnsLabelForCompleteSimpleProduct(): void
    {
        $this->allFieldsFit();
        $this->productResource->expects($this->never())->method('getAttributeRawValue');

        $label = $this->resolver->forProduct($this->createProduct(10, 'SKU-10', [
            Attributes::BRAND => ' Our Demo Brand Name ',
            Attributes::MODEL_IDENTIFIER => 'Model XYZ 01 XBZ a42',
            Attributes::DURATION_YEARS => '4.500000',
            Attributes::TERMS_URL => self::TERMS_URL,
        ]), self::STORE_ID);

        $this->assertInstanceOf(GaranLabelDataInterface::class, $label);
        $this->assertSame([
            GaranLabelDataInterface::PRODUCT_ID => 10,
            GaranLabelDataInterface::PRODUCT_NAME => 'Product SKU-10',
            GaranLabelDataInterface::SKU => 'SKU-10',
            GaranLabelDataInterface::BRAND => 'Our Demo Brand Name',
            GaranLabelDataInterface::MODEL_IDENTIFIER => 'Model XYZ 01 XBZ a42',
            GaranLabelDataInterface::DURATION_YEARS => 4.5,
            GaranLabelDataInterface::TERMS_URL => self::TERMS_URL,
        ], $label->toArray());
    }

    public function testVariantInheritsMissingValuesFromItsConfigurableParent(): void
    {
        $this->allFieldsFit();
        $this->parentIdsByChild[10] = [99];
        $this->productResource->method('getAttributeRawValue')->willReturnCallback(
            static fn (int $id, array $codes): array|string => $id === 99
                ? [
                    Attributes::BRAND => 'Parent Brand',
                    Attributes::MODEL_IDENTIFIER => 'Parent Model',
                    Attributes::DURATION_YEARS => '7.000000',
                ]
                : []
        );

        $label = $this->resolver->forProduct($this->createProduct(10, 'SKU-10', [
            Attributes::BRAND => null,
            Attributes::MODEL_IDENTIFIER => null,
            Attributes::DURATION_YEARS => null,
            Attributes::TERMS_URL => self::TERMS_URL,
        ]), self::STORE_ID);

        $this->assertNotNull($label);
        $this->assertSame('Parent Brand', $label->getBrand());
        $this->assertSame('Parent Model', $label->getModelIdentifier());
        $this->assertSame(7.0, $label->getDurationYears());
    }

    public function testOwnValuesWinOverTheParent(): void
    {
        $this->allFieldsFit();
        $this->parentIdsByChild[10] = [99];
        $this->productResource->method('getAttributeRawValue')
            ->willReturn([Attributes::BRAND => 'Parent Brand']);

        $label = $this->resolver->forProduct($this->createProduct(10, 'SKU-10'), self::STORE_ID);

        $this->assertNotNull($label);
        $this->assertSame('Brand', $label->getBrand());
    }

    public function testConfiguredFixedBrandFillsAnEmptyBrandAttribute(): void
    {
        $this->allFieldsFit();
        $this->config->method('getBrandSource')->willReturn(BrandSource::CONFIG_VALUE);
        $this->config->method('getBrandValue')->with(self::STORE_ID)->willReturn('Config Brand');

        $label = $this->resolver->forProduct($this->createProduct(10, 'SKU-10', [
            Attributes::BRAND => null,
            Attributes::MODEL_IDENTIFIER => 'Model',
            Attributes::DURATION_YEARS => '5.000000',
            Attributes::TERMS_URL => self::TERMS_URL,
        ]), self::STORE_ID);

        $this->assertNotNull($label);
        $this->assertSame('Config Brand', $label->getBrand());
    }

    public function testConfiguredBrandAttributeIsReadFromTheProduct(): void
    {
        $this->allFieldsFit();
        $this->config->method('getBrandSource')->willReturn(BrandSource::PRODUCT_ATTRIBUTE);
        $this->config->method('getBrandAttribute')->with(self::STORE_ID)->willReturn('manufacturer');

        $product = $this->createProduct(10, 'SKU-10', [
            Attributes::BRAND => null,
            Attributes::MODEL_IDENTIFIER => 'Model',
            Attributes::DURATION_YEARS => '5.000000',
            Attributes::TERMS_URL => self::TERMS_URL,
        ]);
        $product->setData('manufacturer', 'Attribute Brand');

        $label = $this->resolver->forProduct($product, self::STORE_ID);

        $this->assertNotNull($label);
        $this->assertSame('Attribute Brand', $label->getBrand());
    }

    public function testProductNameFillsAnEmptyModelIdentifier(): void
    {
        $this->allFieldsFit();
        $this->config->method('getModelIdentifierSource')->willReturn(ModelIdentifierSource::PRODUCT_NAME);

        $label = $this->resolver->forProduct($this->createProduct(10, 'SKU-10', [
            Attributes::BRAND => 'Brand',
            Attributes::MODEL_IDENTIFIER => null,
            Attributes::DURATION_YEARS => '5.000000',
            Attributes::TERMS_URL => self::TERMS_URL,
        ]), self::STORE_ID);

        $this->assertNotNull($label);
        $this->assertSame('Product SKU-10', $label->getModelIdentifier());
    }

    public function testGlobalTermsUrlOnlyAppliesWhenTheProductHasNone(): void
    {
        $this->allFieldsFit();
        $this->config->method('getGaranTermsUrl')->willReturn('https://example.com/global');

        $withoutOwn = $this->resolver->forProduct($this->createProduct(10, 'SKU-10', [
            Attributes::BRAND => 'Brand',
            Attributes::MODEL_IDENTIFIER => 'Model',
            Attributes::DURATION_YEARS => '5.000000',
            Attributes::TERMS_URL => null,
        ]), self::STORE_ID);
        $withOwn = $this->resolver->forProduct($this->createProduct(11, 'SKU-11'), self::STORE_ID);

        $this->assertNotNull($withoutOwn);
        $this->assertSame('https://example.com/global', $withoutOwn->getTermsUrl());
        $this->assertNotNull($withOwn);
        $this->assertSame(self::TERMS_URL, $withOwn->getTermsUrl());
    }

    #[DataProvider('invalidAttributeValues')]
    public function testForProductReturnsNullForIncompleteOrInvalidData(string $code, mixed $value): void
    {
        $this->allFieldsFit();
        $this->productResource->method('getAttributeRawValue')->willReturn([]);
        $values = array_merge($this->validValues(), [$code => $value]);

        $this->assertNull($this->resolver->forProduct($this->createProduct(10, 'SKU-10', $values), self::STORE_ID));
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidAttributeValues(): array
    {
        return [
            'missing brand' => [Attributes::BRAND, null],
            'blank model identifier' => [Attributes::MODEL_IDENTIFIER, '  '],
            'missing duration' => [Attributes::DURATION_YEARS, null],
            'invalid duration written to the DB' => [Attributes::DURATION_YEARS, '4.200000'],
            'duration of two years' => [Attributes::DURATION_YEARS, '2.000000'],
            'missing terms url' => [Attributes::TERMS_URL, ''],
            'invalid terms url' => [Attributes::TERMS_URL, 'www.example.com'],
        ];
    }

    public function testForProductReturnsNullWhenBrandIsTooLong(): void
    {
        $this->fieldFitChecker->method('fitsBrand')->willReturn(false);
        $this->fieldFitChecker->method('fitsModelIdentifier')->willReturn(true);
        $this->fieldFitChecker->method('fitsBrandAndModel')->willReturn(true);
        $this->fieldFitChecker->method('fitsDuration')->willReturn(true);

        $this->assertNull($this->resolver->forProduct($this->createProduct(10, 'SKU-10'), self::STORE_ID));
    }

    public function testForProductReturnsNullWhenBrandAndModelDoNotFitTogether(): void
    {
        $this->fieldFitChecker->method('fitsBrand')->willReturn(true);
        $this->fieldFitChecker->method('fitsModelIdentifier')->willReturn(true);
        $this->fieldFitChecker->method('fitsBrandAndModel')->willReturn(false);
        $this->fieldFitChecker->method('fitsDuration')->willReturn(true);

        $this->assertNull($this->resolver->forProduct($this->createProduct(10, 'SKU-10'), self::STORE_ID));
    }

    public function testForProductReturnsNullWhenDurationDoesNotFit(): void
    {
        $this->fieldFitChecker->method('fitsBrand')->willReturn(true);
        $this->fieldFitChecker->method('fitsModelIdentifier')->willReturn(true);
        $this->fieldFitChecker->method('fitsBrandAndModel')->willReturn(true);
        $this->fieldFitChecker->method('fitsDuration')->with('5')->willReturn(false);

        $this->assertNull($this->resolver->forProduct($this->createProduct(10, 'SKU-10'), self::STORE_ID));
    }

    public function testForProductReturnsLabelForNonSimpleType(): void
    {
        $this->allFieldsFit();

        $label = $this->resolver->forProduct($this->createProduct(10, 'SKU-10', null, 'virtual'), self::STORE_ID);

        $this->assertNotNull($label);
        $this->assertSame('Brand', $label->getBrand());
    }

    public function testForProductReturnsNullWhenSimpleTypeIsExcluded(): void
    {
        $this->allFieldsFit();
        $config = $this->createMock(Config::class);
        $config->method('getExcludedProductTypes')->with(self::STORE_ID)->willReturn(['simple']);
        $resolver = $this->createResolverWithConfig($config);

        $this->assertNull($resolver->forProduct($this->createProduct(10, 'SKU-10'), self::STORE_ID));
    }

    public function testForProductUsesProductStoreWhenNoStoreIsGiven(): void
    {
        $this->allFieldsFit();
        $product = $this->createProduct(10, 'SKU-10', [Attributes::BRAND => 'Brand']);
        $product->setData('store_id', 7);
        $this->productResource->expects($this->once())
            ->method('getAttributeRawValue')
            ->with(10, [Attributes::MODEL_IDENTIFIER, Attributes::DURATION_YEARS, Attributes::TERMS_URL], 7)
            ->willReturn([
                Attributes::MODEL_IDENTIFIER => 'Model',
                Attributes::DURATION_YEARS => '3.000000',
                Attributes::TERMS_URL => self::TERMS_URL,
            ]);

        $label = $this->resolver->forProduct($product);

        $this->assertNotNull($label);
        $this->assertSame('3', $label->getFormattedDuration());
    }

    public function testForProductLoadsMissingValuesWithoutKeepingThemInMemory(): void
    {
        $this->allFieldsFit();
        $this->productResource->expects($this->exactly(2))
            ->method('getAttributeRawValue')
            ->with(10, Attributes::ALL, self::STORE_ID)
            ->willReturn($this->validValues());

        $first = $this->resolver->forProduct($this->createProduct(10, 'SKU-10', []), self::STORE_ID);
        $second = $this->resolver->forProduct($this->createProduct(10, 'SKU-10', []), self::STORE_ID);

        $this->assertNotNull($first);
        $this->assertEquals($first, $second);
    }

    public function testForProductNeverLoadsRawValuesForCollectionChildrenWithNullFilledCodes(): void
    {
        $this->allFieldsFit();
        $this->productResource->expects($this->never())->method('getAttributeRawValue');

        $withoutLabel = $this->createProduct(29281, 'CHILD-NO-DATA', array_fill_keys(Attributes::ALL, null));
        $incomplete = $this->createProduct(29282, 'CHILD-PARTIAL', [
            Attributes::BRAND => 'Brand',
            Attributes::MODEL_IDENTIFIER => null,
            Attributes::DURATION_YEARS => '10.000000',
            Attributes::TERMS_URL => null,
        ]);

        $this->assertNull($this->resolver->forProduct($withoutLabel, self::STORE_ID));
        $this->assertNull($this->resolver->forProduct($incomplete, self::STORE_ID));
        $this->assertNotNull($this->resolver->forProduct($this->createProduct(29279, 'CHILD-OK'), self::STORE_ID));
    }

    public function testForProductTreatsSingleLoadedValueOfSeveralCodesAsIncomplete(): void
    {
        $this->allFieldsFit();
        $this->productResource->method('getAttributeRawValue')->willReturn('Brand');

        $this->assertNull($this->resolver->forProduct($this->createProduct(10, 'SKU-10', []), self::STORE_ID));
    }

    public function testForProductAcceptsSingleLoadedValueOfOneCode(): void
    {
        $this->allFieldsFit();
        $values = $this->validValues();
        unset($values[Attributes::TERMS_URL]);
        $this->productResource->method('getAttributeRawValue')
            ->with(10, [Attributes::TERMS_URL], self::STORE_ID)
            ->willReturn(self::TERMS_URL);

        $this->assertNotNull($this->resolver->forProduct($this->createProduct(10, 'SKU-10', $values), self::STORE_ID));
    }

    public function testForQuoteItemReturnsLabelOfSimpleItem(): void
    {
        $this->allFieldsFit();

        $labels = $this->resolver->forQuoteItem($this->createQuoteItem($this->createProduct(10, 'SKU-10')));

        $this->assertCount(1, $labels);
        $this->assertSame('SKU-10', $labels[0]->getSku());
    }

    public function testForQuoteItemReturnsEmptyListForSimpleItemWithoutLabel(): void
    {
        $this->allFieldsFit();
        $this->productResource->method('getAttributeRawValue')->willReturn([]);

        $this->assertSame([], $this->resolver->forQuoteItem($this->createQuoteItem($this->createProduct(10, 'S', []))));
    }

    public function testForQuoteItemPicksSelectedChildOfConfigurable(): void
    {
        $this->allFieldsFit();
        $child = $this->createQuoteItem($this->createProduct(11, 'CHILD-A'));
        $parent = $this->createQuoteItem($this->createProduct(1, 'PARENT', [], 'configurable'), [$child]);

        $labels = $this->resolver->forQuoteItem($parent);

        $this->assertCount(1, $labels);
        $this->assertSame(11, $labels[0]->getProductId());
    }

    public function testForQuoteItemReturnsAllQualifyingChildrenOfBundle(): void
    {
        $this->allFieldsFit();
        $this->productResource->method('getAttributeRawValue')->willReturn([]);
        $children = [
            $this->createQuoteItem($this->createProduct(21, 'BUNDLE-A')),
            $this->createQuoteItem($this->createProduct(22, 'BUNDLE-NONE', [])),
            $this->createQuoteItem($this->createProduct(23, 'BUNDLE-B')),
        ];
        $bundle = $this->createQuoteItem($this->createProduct(2, 'BUNDLE', [], 'bundle'), $children);

        $labels = $this->resolver->forQuoteItem($bundle);

        $this->assertSame(['BUNDLE-A', 'BUNDLE-B'], array_map(
            static fn (GaranLabelDataInterface $label): string => $label->getSku(),
            $labels
        ));
    }

    public function testForQuoteItemReturnsEmptyListForExcludedParentType(): void
    {
        $this->allFieldsFit();
        $child = $this->createQuoteItem($this->createProduct(31, 'CARD-CHILD'));
        $item = $this->createQuoteItem($this->createProduct(3, 'CARD', [], 'mageworx_giftcards'), [$child]);

        $this->assertSame([], $this->resolver->forQuoteItem($item));
    }

    public function testForQuoteItemUsesQuoteStore(): void
    {
        $this->allFieldsFit();
        $config = $this->createMock(Config::class);
        $config->expects($this->atLeastOnce())
            ->method('getExcludedProductTypes')
            ->with(self::STORE_ID)
            ->willReturn([]);

        $this->assertCount(
            1,
            $this->createResolverWithConfig($config)->forQuoteItem(
                $this->createQuoteItem($this->createProduct(10, 'SKU-10'))
            )
        );
    }

    public function testForOrderItemRebuildsLabelsFromSnapshot(): void
    {
        $orderItem = $this->createOrderItem(json_encode([
            $this->snapshotEntry(21, 'BUNDLE-A', 4.5),
            $this->snapshotEntry(23, 'BUNDLE-B', 3.0),
        ]));

        $labels = $this->resolver->forOrderItem($orderItem);

        $this->assertCount(2, $labels);
        $this->assertSame($this->snapshotEntry(21, 'BUNDLE-A', 4.5), $labels[0]->toArray());
        $this->assertSame('4,5', $labels[0]->getFormattedDuration());
        $this->assertSame('3', $labels[1]->getFormattedDuration());
    }

    public function testForOrderItemSkipsMalformedEntries(): void
    {
        $incomplete = $this->snapshotEntry(22, 'NO-BRAND', 5.0);
        unset($incomplete[GaranLabelDataInterface::BRAND]);
        $invalidDuration = $this->snapshotEntry(24, 'BAD-DURATION', 4.2);
        $orderItem = $this->createOrderItem(json_encode([
            'not an entry',
            $incomplete,
            $this->snapshotEntry(23, 'VALID', 5.0),
            $invalidDuration,
        ]));
        $this->logger->expects($this->exactly(3))->method('debug');

        $labels = $this->resolver->forOrderItem($orderItem);

        $this->assertCount(1, $labels);
        $this->assertSame('VALID', $labels[0]->getSku());
    }

    public function testForOrderItemReturnsEmptyListForMalformedJson(): void
    {
        $this->logger->expects($this->once())->method('debug');

        $this->assertSame([], $this->resolver->forOrderItem($this->createOrderItem('[{"brand":')));
    }

    public function testForOrderItemReturnsEmptyListForNonListJson(): void
    {
        $this->logger->expects($this->once())->method('debug');

        $this->assertSame([], $this->resolver->forOrderItem($this->createOrderItem('"text"')));
    }

    public function testForOrderItemReturnsEmptyListWithoutSnapshot(): void
    {
        $this->logger->expects($this->never())->method('debug');

        $this->assertSame([], $this->resolver->forOrderItem($this->createOrderItem(null)));
        $this->assertSame([], $this->resolver->forOrderItem($this->createOrderItem('')));
    }

    public function testPrefetchedParentAndValuesReplaceEveryQuery(): void
    {
        $this->allFieldsFit();
        $prefetched = new PrefetchedValues();
        $prefetched->setParentId(10, 20);
        foreach (Attributes::ALL as $code) {
            $prefetched->setValue(10, $code, self::STORE_ID, null);
        }
        foreach ($this->validValues() as $code => $value) {
            $prefetched->setValue(20, $code, self::STORE_ID, $value);
        }
        $this->productResource->expects($this->never())->method('getAttributeRawValue');
        $this->configurableResource->expects($this->never())->method('getParentIdsByChild');

        $resolver = $this->createResolverWithPrefetch($prefetched);
        $label = $resolver->forProduct($this->createProduct(10, 'CHILD', []), self::STORE_ID);

        self::assertNotNull($label);
        self::assertSame('Brand', $label->getBrand());
        self::assertSame('Model', $label->getModelIdentifier());
        self::assertSame(5.0, $label->getDurationYears());
    }

    public function testUnknownProductsStillFallBackToTheirOwnQueries(): void
    {
        $this->allFieldsFit();
        $this->productResource->expects($this->atLeastOnce())->method('getAttributeRawValue')->willReturn([]);

        $resolver = $this->createResolverWithPrefetch(new PrefetchedValues());

        self::assertNull($resolver->forProduct($this->createProduct(11, 'OTHER', []), self::STORE_ID));
    }

    private function createResolverWithPrefetch(PrefetchedValues $prefetched): Resolver
    {
        $factory = $this->getMockBuilder(GaranLabelDataFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(
            static fn (array $data): GaranLabelData => new GaranLabelData(...$data)
        );
        $durationParser = new DurationParser();
        $this->config->method('getBrandSource')->willReturn(BrandSource::GARAN_ATTRIBUTE);
        $this->config->method('getModelIdentifierSource')->willReturn(ModelIdentifierSource::GARAN_ATTRIBUTE);

        return new Resolver(
            $this->config,
            new LabelValidator($durationParser, $this->fieldFitChecker),
            $durationParser,
            $this->productResource,
            $this->configurableResource,
            $factory,
            new Json(),
            $this->logger,
            $prefetched
        );
    }

    private function allFieldsFit(): void
    {
        $this->fieldFitChecker->method('fitsBrand')->willReturn(true);
        $this->fieldFitChecker->method('fitsModelIdentifier')->willReturn(true);
        $this->fieldFitChecker->method('fitsBrandAndModel')->willReturn(true);
        $this->fieldFitChecker->method('fitsDuration')->willReturn(true);
    }

    /**
     * @return array<string, string>
     */
    private function validValues(): array
    {
        return [
            Attributes::BRAND => 'Brand',
            Attributes::MODEL_IDENTIFIER => 'Model',
            Attributes::DURATION_YEARS => '5.000000',
            Attributes::TERMS_URL => self::TERMS_URL,
        ];
    }

    /**
     * @param array<string, mixed>|null $garanValues null = complete valid values
     */
    private function createProduct(int $id, string $sku, ?array $garanValues = null, string $typeId = 'simple'): Product
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSku'])
            ->getMock();
        $product->method('getSku')->willReturn($sku);
        $product->setData(array_merge(
            ['type_id' => $typeId, 'sku' => $sku, 'name' => 'Product ' . $sku],
            $garanValues ?? $this->validValues()
        ));
        $product->setId($id);

        return $product;
    }

    /**
     * @param list<QuoteItem> $children
     */
    private function createQuoteItem(Product $product, array $children = []): QuoteItem
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(self::STORE_ID);
        $item = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProduct', 'getChildren', 'getQuote'])
            ->getMock();
        $item->method('getProduct')->willReturn($product);
        $item->method('getChildren')->willReturn($children);
        $item->method('getQuote')->willReturn($quote);

        return $item;
    }

    private function createOrderItem(?string $snapshot): OrderItem
    {
        $item = $this->getMockBuilder(OrderItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $item->setData(Attributes::ORDER_ITEM_SNAPSHOT_COLUMN, $snapshot);

        return $item;
    }

    /**
     * @return array<string, int|string|float>
     */
    private function snapshotEntry(int $productId, string $sku, float $duration): array
    {
        return [
            GaranLabelDataInterface::PRODUCT_ID => $productId,
            GaranLabelDataInterface::PRODUCT_NAME => 'Product ' . $sku,
            GaranLabelDataInterface::SKU => $sku,
            GaranLabelDataInterface::BRAND => 'Brand & Co <"Test">',
            GaranLabelDataInterface::MODEL_IDENTIFIER => 'Model',
            GaranLabelDataInterface::DURATION_YEARS => $duration,
            GaranLabelDataInterface::TERMS_URL => self::TERMS_URL,
        ];
    }

    private function createResolverWithConfig(Config $config): Resolver
    {
        $factory = $this->getMockBuilder(GaranLabelDataFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(
            static fn (array $data): GaranLabelData => new GaranLabelData(...$data)
        );
        $durationParser = new DurationParser();

        return new Resolver(
            $config,
            new LabelValidator($durationParser, $this->fieldFitChecker),
            $durationParser,
            $this->productResource,
            $this->configurableResource,
            $factory,
            new Json(),
            $this->logger
        );
    }
}
