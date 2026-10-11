<?php

declare(strict_types=1);

namespace Civi\Cmcic\CheckoutOption;

use PHPUnit\Framework\TestCase;

final class CmcicHostedCheckoutTest extends TestCase
{
    public function testPaymentProcessorIdFollowsCheckoutMode(): void
    {
        $checkout = new CmcicHostedCheckout(
            ['id' => '10'],
            ['id' => '11']
        );

        self::assertSame(10, $checkout->getPaymentProcessorId());
        self::assertSame(10, $checkout->getPaymentProcessorId(false));
        self::assertSame(11, $checkout->getPaymentProcessorId(true));
    }

    public function testPaymentProcessorIdIsNullWhenModeIsUnavailable(): void
    {
        $checkout = new CmcicHostedCheckout(['id' => '10'], null);

        self::assertNull($checkout->getPaymentProcessorId(true));
    }
}
