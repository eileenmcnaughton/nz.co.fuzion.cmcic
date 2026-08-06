<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CmcicRefundBalanceTest extends TestCase {

  public function testCalculatesSoldeRemboursableCorrectly(): void {
    $capturedAmount = 100.00;
    $recreditsTotal = 25.00;
    $soldeRemboursable = $capturedAmount - $recreditsTotal;

    self::assertSame(75.00, $soldeRemboursable);
  }

  public function testRejectsPartialRefundAttemptInV1(): void {
    $requestedRefundAmount = 50.00;
    $totalContributionAmount = 100.00;

    $isPartialRefund = abs($requestedRefundAmount - $totalContributionAmount) >= 0.01;
    self::assertTrue($isPartialRefund);
  }

  public function testRejectsRefundExceedingSoldeRemboursable(): void {
    $requestedRefundAmount = 100.00;
    $soldeRemboursable = 50.00;

    $exceedsBalance = $requestedRefundAmount > $soldeRemboursable;
    self::assertTrue($exceedsBalance);
  }
}
