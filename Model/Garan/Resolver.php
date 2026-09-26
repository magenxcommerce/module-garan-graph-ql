<?php

declare(strict_types=1);

namespace Magenx\GaranGraphQl\Model\Garan;

use Magenx\GaranGraphQl\Api\Data\GaranLabelDataInterface;
use Magenx\GaranGraphQl\Api\GaranLabelResolverInterface;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Source\BrandSource;
use Magenx\GaranGraphQl\Model\Source\ModelIdentifierSource;
use InvalidArgumentException;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Psr\Log\LoggerInterface;
use Throwable;

class Resolver implements GaranLabelResolverInterface
{
    /**
     * @var array<int, int>
     */
    private array $parentIds = [];

    public function __construct(
        private readonly Config $config,
        private readonly LabelValidator $labelValidator,
        private readonly DurationParser $durationParser,
        private readonly ProductResource $productResource,
        private readonly ConfigurableResource $configurableResource,
        private readonly GaranLabelDataFactory $garanLabelDataFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly ?PrefetchedValues $prefetchedValues = null
    ) {
    }

    public function forProduct(ProductInterface $product, ?int $storeId = null): ?GaranLabelDataInterface
    {
        $typeId = (string) $product->getTypeId();
        $storeId ??= $product instanceof Product ? (int) $product->getStoreId() : 0;
        if ($this->isExcludedType($typeId, $storeId)) {
            return null;
        }

        $values = $this->resolveValues($product, $storeId);
        $violations = $this->labelValidator->getViolations(
            $values[Attributes::BRAND],
            $values[Attributes::MODEL_IDENTIFIER],
            $values[Attributes::DURATION_YEARS],
            $values[Attributes::TERMS_URL]
        );
        if ($violations !== []) {
            return null;
        }

        return $this->createLabel(
            (int) $product->getId(),
            (string) $product->getName(),
            (string) $product->getSku(),
            $this->labelValidator->normalizeText($values[Attributes::BRAND]),
            $this->labelValidator->normalizeText($values[Attributes::MODEL_IDENTIFIER]),
            (float) $this->durationParser->parse($values[Attributes::DURATION_YEARS]),
            $this->labelValidator->normalizeText($values[Attributes::TERMS_URL])
        );
    }

    public function forQuoteItem(AbstractItem $item): array
    {
        $storeId = $this->getQuoteItemStoreId($item);
        $product = $item->getProduct();
        if (!$product instanceof ProductInterface || $this->isExcludedType((string) $product->getTypeId(), $storeId)) {
            return [];
        }

        $children = $item->getChildren();
        $sources = count($children) > 0 ? $children : [$item];
        $labels = [];
        foreach ($sources as $source) {
            $sourceProduct = $source->getProduct();
            $label = $sourceProduct instanceof ProductInterface ? $this->forProduct($sourceProduct, $storeId) : null;
            if ($label !== null) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    public function forOrderItem(OrderItem $item): array
    {
        $snapshot = $item->getData(Attributes::ORDER_ITEM_SNAPSHOT_COLUMN);
        if (!is_string($snapshot) || trim($snapshot) === '') {
            return [];
        }

        try {
            $entries = $this->json->unserialize($snapshot);
        } catch (InvalidArgumentException $exception) {
            $this->logMalformedSnapshot($item, $exception->getMessage());
            return [];
        }
        if (!is_array($entries)) {
            $this->logMalformedSnapshot($item, 'snapshot is not a list');
            return [];
        }

        $labels = [];
        foreach ($entries as $entry) {
            $label = is_array($entry) ? $this->fromSnapshotEntry($entry) : null;
            if ($label === null) {
                $this->logMalformedSnapshot($item, 'invalid entry skipped');
                continue;
            }
            $labels[] = $label;
        }

        return $labels;
    }

    /**
     * @param array<mixed> $entry
     */
    private function fromSnapshotEntry(array $entry): ?GaranLabelDataInterface
    {
        $productId = $entry[GaranLabelDataInterface::PRODUCT_ID] ?? null;
        $productName = $entry[GaranLabelDataInterface::PRODUCT_NAME] ?? null;
        $sku = $entry[GaranLabelDataInterface::SKU] ?? null;
        $brand = $entry[GaranLabelDataInterface::BRAND] ?? null;
        $model = $entry[GaranLabelDataInterface::MODEL_IDENTIFIER] ?? null;
        $termsUrl = $entry[GaranLabelDataInterface::TERMS_URL] ?? null;
        $duration = $this->durationParser->parse($entry[GaranLabelDataInterface::DURATION_YEARS] ?? null);

        if (!is_int($productId) && !(is_string($productId) && ctype_digit($productId))) {
            return null;
        }
        if (!is_string($productName) || !is_string($sku) || !is_string($brand) || !is_string($model)
            || !is_string($termsUrl) || $brand === '' || $model === '' || $termsUrl === '' || $duration === null
        ) {
            return null;
        }

        return $this->createLabel((int) $productId, $productName, $sku, $brand, $model, $duration, $termsUrl);
    }

    private function createLabel(
        int $productId,
        string $productName,
        string $sku,
        string $brand,
        string $modelIdentifier,
        float $durationYears,
        string $termsUrl
    ): GaranLabelDataInterface {
        return $this->garanLabelDataFactory->create([
            'productId' => $productId,
            'productName' => $productName,
            'sku' => $sku,
            'brand' => $brand,
            'modelIdentifier' => $modelIdentifier,
            'durationYears' => $durationYears,
            'termsUrl' => $termsUrl,
        ]);
    }

    /**
     * Label attribute values of the product; codes not present on the product at all are read from the database.
     *
     * A code present with value null counts as loaded and empty: callers iterating a collection that selected the
     * label attributes set the missing codes to null, so no query per product is issued.
     *
     * @return array<string, mixed>
     */
    private function getAttributeValues(ProductInterface $product, int $storeId): array
    {
        $values = [];
        $missing = [];
        foreach (Attributes::ALL as $code) {
            $values[$code] = null;
            if ($product instanceof DataObject && $product->hasData($code)) {
                $values[$code] = $product->getData($code);
            } elseif (!$product instanceof DataObject && $product->getCustomAttribute($code) !== null) {
                $values[$code] = $product->getCustomAttribute($code)->getValue();
            } else {
                $missing[] = $code;
            }
        }

        $productId = (int) $product->getId();
        if ($missing === [] || $productId === 0) {
            return $values;
        }

        return array_merge($values, $this->loadRawValues($productId, $missing, $storeId));
    }

    /**
     * Label values in order of precedence: the product's own GARAN attributes, then the values of its configurable
     * parent, then the sources configured in the admin.
     *
     * @return array<string, mixed>
     */
    public function resolveValues(ProductInterface $product, int $storeId): array
    {
        $values = $this->getAttributeValues($product, $storeId);
        $values = $this->inheritFromParent($values, $product, $storeId);

        if ($this->isBlank($values[Attributes::BRAND])) {
            $values[Attributes::BRAND] = $this->getConfiguredBrand($product, $storeId);
        }
        if ($this->isBlank($values[Attributes::MODEL_IDENTIFIER])
            && $this->config->getModelIdentifierSource($storeId) === ModelIdentifierSource::PRODUCT_NAME
        ) {
            $values[Attributes::MODEL_IDENTIFIER] = (string) $product->getName();
        }
        if ($this->isBlank($values[Attributes::TERMS_URL])) {
            $values[Attributes::TERMS_URL] = $this->config->getGaranTermsUrl($storeId);
        }

        return $values;
    }

    /**
     * A variant without its own values takes those of its configurable parent.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function inheritFromParent(array $values, ProductInterface $product, int $storeId): array
    {
        $missing = array_keys(array_filter($values, fn (mixed $value): bool => $this->isBlank($value)));
        $productId = (int) $product->getId();
        if ($missing === [] || $productId === 0 || (string) $product->getTypeId() !== ProductType::TYPE_SIMPLE) {
            return $values;
        }

        $parentId = $this->getParentId($productId);
        if ($parentId === 0) {
            return $values;
        }

        foreach ($this->loadRawValues($parentId, $missing, $storeId) as $code => $value) {
            if (!$this->isBlank($value)) {
                $values[$code] = $value;
            }
        }

        return $values;
    }

    private function getParentId(int $productId): int
    {
        $prefetched = $this->prefetchedValues?->getParentId($productId);
        if ($prefetched !== null) {
            return $prefetched;
        }
        if (!array_key_exists($productId, $this->parentIds)) {
            $parents = $this->configurableResource->getParentIdsByChild($productId);
            $this->parentIds[$productId] = $parents === [] ? 0 : (int) reset($parents);
        }

        return $this->parentIds[$productId];
    }

    private function getConfiguredBrand(ProductInterface $product, int $storeId): ?string
    {
        return match ($this->config->getBrandSource($storeId)) {
            BrandSource::PRODUCT_ATTRIBUTE => $this->readAttribute(
                $product,
                $this->config->getBrandAttribute($storeId),
                $storeId
            ),
            BrandSource::CONFIG_VALUE => $this->config->getBrandValue($storeId),
            default => null,
        };
    }

    /**
     * Value of any product attribute, resolved to its option label when it is a select.
     */
    private function readAttribute(ProductInterface $product, string $code, int $storeId): ?string
    {
        if ($code === '' || !$product instanceof Product) {
            return null;
        }

        try {
            $text = $product->getAttributeText($code);
            if (is_string($text) && $text !== '') {
                return $text;
            }
        } catch (Throwable) {
            // A free-text attribute has no source model and a missing one no attribute at all;
            // the raw value below answers in both cases.
        }

        $value = $product->getData($code);
        if ($this->isBlank($value)) {
            $value = $this->loadRawValues((int) $product->getId(), [$code], $storeId)[$code] ?? null;
        }

        return $this->isBlank($value) ? null : (string) $value;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === false;
    }

    /**
     * @param list<string> $codes
     * @return array<string, mixed>
     */
    private function loadRawValues(int $productId, array $codes, int $storeId): array
    {
        $prefetched = $this->prefetchedValues?->getValues($productId, $codes, $storeId);
        if ($prefetched !== null) {
            return $prefetched;
        }

        $raw = $this->productResource->getAttributeRawValue($productId, $codes, $storeId);
        if (is_array($raw)) {
            return array_intersect_key($raw, array_flip($codes));
        }
        if (count($codes) === 1) {
            return $raw === false ? [] : [$codes[0] => $raw];
        }

        // A scalar answer to several codes means exactly one of them is filled, and which one is not part of the
        // answer. The remaining fields may still come from the configuration, so ask for each code separately.
        $values = [];
        foreach ($codes as $code) {
            $single = $this->productResource->getAttributeRawValue($productId, [$code], $storeId);
            if (!is_array($single) && $single !== false) {
                $values[$code] = $single;
            } elseif (is_array($single) && array_key_exists($code, $single)) {
                $values[$code] = $single[$code];
            }
        }

        return $values;
    }

    private function isExcludedType(string $typeId, int $storeId): bool
    {
        return in_array($typeId, $this->config->getExcludedProductTypes($storeId), true);
    }

    private function getQuoteItemStoreId(AbstractItem $item): int
    {
        $quote = $item->getQuote();
        if ($quote !== null && $quote->getStoreId() !== null) {
            return (int) $quote->getStoreId();
        }

        return (int) $item->getData('store_id');
    }

    private function logMalformedSnapshot(OrderItem $item, string $reason): void
    {
        $this->logger->debug(
            sprintf('Magenx_GaranGraphQl: malformed GARAN snapshot on order item %s: %s', $item->getId(), $reason)
        );
    }
}
