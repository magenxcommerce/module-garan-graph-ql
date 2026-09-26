<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Resolver\Product;

use Magenx\GaranGraphQl\Api\Data\GaranLabelDataInterface;
use Magenx\GaranGraphQl\Api\GaranLabelResolverInterface;
use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Garan\BatchLoader;
use Magenx\GaranGraphQl\Model\Garan\GaranLabelData;
use Magenx\GaranGraphQl\Model\Garan\LabelPresenter;
use Magenx\GaranGraphQl\Model\Render\GaranPngRenderer;
use Magenx\GaranGraphQl\Model\Resolver\Product\GaranLabel;
use Magento\Catalog\Model\Product;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchRequestItemInterface;
use Magento\GraphQl\Model\Query\ContextExtensionInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class GaranLabelTest extends TestCase
{
    private const STORE_ID = 4;

    private Config&MockObject $config;
    private BatchLoader&MockObject $batchLoader;
    private GaranLabelResolverInterface&MockObject $labelResolver;
    private GaranPngRenderer&MockObject $pngRenderer;
    private GaranLabel $resolver;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->batchLoader = $this->createMock(BatchLoader::class);
        $this->labelResolver = $this->createMock(GaranLabelResolverInterface::class);
        $this->pngRenderer = $this->createMock(GaranPngRenderer::class);
        $this->pngRenderer->method('getUrl')->willReturnCallback(
            static fn (GaranLabelDataInterface $label, string $variant): string
                => 'https://shop.test/media/' . $variant . '.png'
        );

        $this->resolver = new GaranLabel(
            $this->config,
            $this->batchLoader,
            $this->labelResolver,
            new LabelPresenter($this->pngRenderer),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testDisabledLabelAnswersNullWithoutLoading(): void
    {
        $this->config->method('isGaranActive')->with(self::STORE_ID)->willReturn(false);
        $this->batchLoader->expects($this->never())->method('prefetch');
        $request = $this->createRequest($this->createProduct(1));

        $response = $this->resolver->resolve($this->createContext(), $this->createMock(Field::class), [$request]);

        self::assertNull($response->findResponseFor($request));
    }

    public function testWholeBranchIsPrefetchedOnceAndMappedPerProduct(): void
    {
        $this->config->method('isGaranActive')->willReturn(true);
        $first = $this->createProduct(1);
        $second = $this->createProduct(2);
        $this->batchLoader->expects($this->once())->method('prefetch')->with([$first, $second], self::STORE_ID);
        $this->labelResolver->method('forProduct')->willReturnCallback(
            fn (Product $product): ?GaranLabelDataInterface => (int) $product->getId() === 1 ? $this->createLabel() : null
        );
        $withLabel = $this->createRequest($first);
        $withoutLabel = $this->createRequest($second);
        $noModel = $this->createRequest(null);

        $response = $this->resolver->resolve(
            $this->createContext(),
            $this->createMock(Field::class),
            [$withLabel, $withoutLabel, $noModel]
        );

        $data = $response->findResponseFor($withLabel);
        self::assertSame('Brand', $data['brand']);
        self::assertSame('Model', $data['model_identifier']);
        self::assertSame('4,5', $data['formatted_duration']);
        self::assertSame('https://shop.test/media/full.png', $data['image_url']);
        self::assertSame('https://shop.test/media/nested.png', $data['nested_image_url']);
        self::assertNull($response->findResponseFor($withoutLabel));
        self::assertNull($response->findResponseFor($noModel));
    }

    public function testFailuresNeverBreakTheQuery(): void
    {
        $this->config->method('isGaranActive')->willReturn(true);
        $this->batchLoader->method('prefetch')->willThrowException(new RuntimeException('db down'));
        $this->labelResolver->method('forProduct')->willThrowException(new RuntimeException('broken'));
        $request = $this->createRequest($this->createProduct(1));

        $response = $this->resolver->resolve($this->createContext(), $this->createMock(Field::class), [$request]);

        self::assertNull($response->findResponseFor($request));
    }

    private function createContext(): ContextInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $extension = $this->createMock(ContextExtensionInterface::class);
        $extension->method('getStore')->willReturn($store);
        $context = $this->createMock(ContextInterface::class);
        $context->method('getExtensionAttributes')->willReturn($extension);

        return $context;
    }

    private function createRequest(?Product $product): BatchRequestItemInterface
    {
        $request = $this->createMock(BatchRequestItemInterface::class);
        $request->method('getValue')->willReturn($product === null ? [] : ['model' => $product]);

        return $request;
    }

    private function createProduct(int $id): Product
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $product->setId($id);

        return $product;
    }

    private function createLabel(): GaranLabelDataInterface
    {
        return new GaranLabelData(1, 'Kettle', 'KETTLE-1', 'Brand', 'Model', 4.5, 'https://brand.test/terms');
    }
}
