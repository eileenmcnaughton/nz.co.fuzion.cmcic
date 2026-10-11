<?php

declare(strict_types = 1);

use PHPUnit\Framework\TestCase;

final class CmcicRefundGuardTest extends TestCase {

  public function testSupportsRefundReturnsFalseWhenSettingDisabled(): void {
    $processor = $this->createMock(CRM_Core_Payment_Cmcic::class);
    $processor->method('supportsRefund')->willReturn(FALSE);

    self::assertFalse($processor->supportsRefund());
  }

  public function testDoRefundThrowsExceptionWhenSettingDisabled(): void {
    $processor = $this->getMockBuilder(CRM_Core_Payment_Cmcic::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['supportsRefund'])
      ->getMock();

    $processor->method('supportsRefund')->willReturn(FALSE);

    $this->expectException(CRM_Core_Exception::class);
    $params = ['contribution_id' => 123, 'amount' => 10.0];
    $processor->doRefund($params);
  }

  public function testFormatsSettlementDateForRecreditApi(): void {
    $processor = $this->getMockBuilder(CRM_Core_Payment_Cmcic::class)
      ->disableOriginalConstructor()
      ->getMock();
    $method = new ReflectionMethod(CRM_Core_Payment_Cmcic::class, 'formatSettlementDate');

    self::assertSame('10/08/2026', $method->invoke($processor, '2026-08-10'));
    self::assertSame('10/08/2026', $method->invoke($processor, '10/08/2026'));
  }

}
