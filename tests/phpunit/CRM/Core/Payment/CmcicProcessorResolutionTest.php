<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CmcicProcessorResolutionTest extends TestCase {

  public function testReturnsSelfWhenCurrentProcessorMatchesRequestedMode(): void {
    $processor = $this->getMockBuilder(CRM_Core_Payment_Cmcic::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getPaymentProcessor'])
      ->getMock();

    $processor->method('getPaymentProcessor')->willReturn([
      'id' => 277,
      'name' => 'MONETICO',
      'is_test' => 1,
    ]);

    self::assertSame($processor, $processor->getOperationalProcessor('test'));
  }

  public function testThrowsExceptionWhenModeDoesNotMatch(): void {
    $processor = $this->getMockBuilder(CRM_Core_Payment_Cmcic::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getPaymentProcessor'])
      ->getMock();

    $processor->method('getPaymentProcessor')->willReturn([
      'id' => 278,
      'name' => 'NON_EXISTENT_PROCESSOR_TEST_MODE',
      'is_test' => 0,
    ]);

    $this->expectException(CRM_Core_Exception::class);
    $processor->getOperationalProcessor('test');
  }
}
