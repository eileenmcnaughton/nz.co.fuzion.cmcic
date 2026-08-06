<?php

declare(strict_types=1);

use Civi\Api4\Action\CmcicPayment\Reconcile;
use PHPUnit\Framework\TestCase;

final class CmcicReconcileUnitTest extends TestCase {

  public function testDetectsCiviRefundedWhileBankNotRecreditedDiscrepancy(): void {
    $decision = Reconcile::determineDiscrepancyAction('Refunded', 'PA', 0.0, 100.00);

    self::assertSame('civi_refunded_while_bank_not_recredited_requires_attention', $decision['action_taken']);
    self::assertSame('Refunded', $decision['target_status']);
  }

  public function testDetectsBankRefundedWhileCiviPendingDiscrepancy(): void {
    $decision = Reconcile::determineDiscrepancyAction('Pending', 'PA', 100.00, 100.00);

    self::assertSame('bank_refunded_while_civi_pending_requires_attention', $decision['action_taken']);
    self::assertSame('Pending', $decision['target_status']);
  }

  public function testMarksRefundedWhenBankIsRefundedAndCiviIsCompleted(): void {
    $decision = Reconcile::determineDiscrepancyAction('Completed', 'PA', 100.00, 100.00);

    self::assertSame('marked_refunded', $decision['action_taken']);
    self::assertSame('Refunded', $decision['target_status']);
  }

  public function testCompletesTransactionWhenBankIsCapturedAndCiviIsPending(): void {
    $decision = Reconcile::determineDiscrepancyAction('Pending', 'PA', 0.0, 100.00);

    self::assertSame('completed_transaction', $decision['action_taken']);
    self::assertSame('Completed', $decision['target_status']);
  }

  public function testBuildsRefundPaymentValuesWithCancelledPaymentId(): void {
    $values = Reconcile::buildRefundPaymentValues(
      85816,
      150.00,
      'EUR',
      277,
      9941
    );

    self::assertSame(85816, $values['contribution_id']);
    self::assertSame(-150.00, $values['total_amount']);
    self::assertSame('EUR', $values['currency']);
    self::assertSame(277, $values['payment_processor_id']);
    self::assertSame(9941, $values['cancelled_payment_id']);
    self::assertSame('REFUND-85816', $values['trxn_id']);
  }
}
