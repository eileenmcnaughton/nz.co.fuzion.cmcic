<?php

declare(strict_types = 1);

use PHPUnit\Framework\TestCase;

final class CheckoutModeTest extends TestCase {

  public function testBothCheckoutModesAreExposed(): void {
    self::assertSame([
      CRM_Cmcic_CheckoutMode::HOSTED_PAGE => 'Monetico hosted payment page',
      CRM_Cmcic_CheckoutMode::HOSTED_FIELDS => 'Monetico Hosted Fields (experimental)',
    ], CRM_Cmcic_CheckoutMode::getOptions());
    self::assertTrue(CRM_Cmcic_CheckoutMode::isImplemented(CRM_Cmcic_CheckoutMode::HOSTED_PAGE));
    self::assertTrue(CRM_Cmcic_CheckoutMode::isImplemented(CRM_Cmcic_CheckoutMode::HOSTED_FIELDS));
  }

  public function testProcessorRoutesHostedPageThroughCommonContract(): void {
    $processor = $this->getMockBuilder(CRM_Core_Payment_Cmcic::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['prepareHostedCheckout'])
      ->getMock();
    $processor->expects(self::once())
      ->method('prepareHostedCheckout')
      ->with(['amount' => '1.00'], 'https://example.test/success', 'https://example.test/cancel', '2-42')
      ->willReturn('https://example.test/relay');

    self::assertSame('https://example.test/relay', $processor->prepareCheckout(
        ['amount' => '1.00'],
        'https://example.test/success',
        'https://example.test/cancel',
        '2-42',
        CRM_Cmcic_CheckoutMode::HOSTED_PAGE
    ));
  }

  public function testProcessorRoutesHostedFieldsThroughCommonContract(): void {
    $processor = $this->getMockBuilder(CRM_Core_Payment_Cmcic::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['prepareHostedFieldsCheckout'])
      ->getMock();
    $processor->expects(self::once())
      ->method('prepareHostedFieldsCheckout')
      ->with(['amount' => '1.00'], 'https://example.test/success', 'https://example.test/cancel')
      ->willReturn('https://example.test/hosted-fields');

    self::assertSame('https://example.test/hosted-fields', $processor->prepareCheckout(
        ['amount' => '1.00'],
        'https://example.test/success',
        'https://example.test/cancel',
        '2-42',
        CRM_Cmcic_CheckoutMode::HOSTED_FIELDS
    ));
  }

}
