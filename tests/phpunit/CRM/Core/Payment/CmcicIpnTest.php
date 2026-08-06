<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CmcicIpnTest extends TestCase {

  public function testZeroDecimalCurrencyDetection(): void {
    $ipn = $this->getMockBuilder(CRM_Core_Payment_CmcicIPN::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    $ref = new ReflectionMethod(CRM_Core_Payment_CmcicIPN::class, 'isZeroDecimalCurrency');


    self::assertTrue($ref->invoke($ipn, 'JPY'));
    self::assertTrue($ref->invoke($ipn, 'KRW'));
    self::assertFalse($ref->invoke($ipn, 'EUR'));
    self::assertFalse($ref->invoke($ipn, 'USD'));
  }

  public function testAmountToMinorUnitsConversion(): void {
    $ipn = $this->getMockBuilder(CRM_Core_Payment_CmcicIPN::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    $ref = new ReflectionMethod(CRM_Core_Payment_CmcicIPN::class, 'amountToMinorUnits');


    self::assertSame(1212, $ref->invoke($ipn, 12.12, 'EUR'));
    self::assertSame(1000, $ref->invoke($ipn, 1000.00, 'JPY'));
  }
}
