<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Resolver\Cart;

use Magenx\GaranGraphQl\Model\Config;
use Magenx\GaranGraphQl\Model\Resolver\Cart\GaranNoticeRequired;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GaranNoticeRequiredTest extends TestCase
{
    private Config&MockObject $config;
    private GaranNoticeRequired $resolver;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getExcludedProductTypes')->willReturn(['virtual', 'giftcard']);
        $this->resolver = new GaranNoticeRequired($this->config);
    }

    public function testRequiredWhenOneLineIsAGood(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        self::assertTrue($this->resolveFor(['giftcard', 'simple']));
    }

    public function testNotRequiredForNonGoodsOnlyOrWhenDisabled(): void
    {
        $this->config->method('isEnabled')->willReturnOnConsecutiveCalls(true, false);

        self::assertFalse($this->resolveFor(['giftcard', 'virtual']));
        self::assertFalse($this->resolveFor(['simple']));
    }

    public function testMissingCartIsNotRequired(): void
    {
        self::assertFalse($this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            []
        ));
    }

    /**
     * @param list<string> $types
     */
    private function resolveFor(array $types): bool
    {
        $items = [];
        foreach ($types as $type) {
            $item = $this->createMock(QuoteItem::class);
            $item->method('getProductType')->willReturn($type);
            $items[] = $item;
        }
        $cart = $this->createMock(Quote::class);
        $cart->method('getStoreId')->willReturn(1);
        $cart->method('getAllVisibleItems')->willReturn($items);

        return $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            ['model' => $cart]
        );
    }
}
