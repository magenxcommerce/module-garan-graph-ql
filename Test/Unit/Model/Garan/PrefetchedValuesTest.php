<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\GaranGraphQl\Test\Unit\Model\Garan;

use Magenx\GaranGraphQl\Model\Garan\PrefetchedValues;
use PHPUnit\Framework\TestCase;

class PrefetchedValuesTest extends TestCase
{
    public function testPrefetchedValuesAreServedWithinTheRequest(): void
    {
        $prefetched = new PrefetchedValues();
        $prefetched->setParentId(10, 7);
        $prefetched->setValue(10, 'brand', 1, 'Acme');

        $this->assertSame(7, $prefetched->getParentId(10));
        $this->assertSame(['brand' => 'Acme'], $prefetched->getValues(10, ['brand'], 1));
    }

    /**
     * On a long-lived application server the next request must look values up again rather than reuse ones that
     * may have changed since.
     */
    public function testRequestStateResetForgetsEverything(): void
    {
        $prefetched = new PrefetchedValues();
        $prefetched->setParentId(10, 7);
        $prefetched->setValue(10, 'brand', 1, 'Acme');

        $prefetched->_resetState();

        $this->assertNull($prefetched->getParentId(10));
        $this->assertNull($prefetched->getValues(10, ['brand'], 1));
    }
}
