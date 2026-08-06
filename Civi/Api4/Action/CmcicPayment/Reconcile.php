<?php

namespace Civi\Api4\Action\CmcicPayment;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use CRM_Core_Exception;
use CRM_Core_Payment_CmcicPaymentStatus;

/**
 * Reconcile a Monetico payment status with CiviCRM contribution.
 *
 * @method $this setContributionId(int $contributionId) Set contribution ID.
 * @method $this setTrxnId(string $trxnId) Set transaction ID.
 * @method $this setDryRun(bool $dryRun) Set dry run mode.
 */
class Reconcile extends AbstractAction {

  /**
   * Contribution ID to reconcile.
   * @var int|null
   */
  protected ?int $contributionId = NULL;

  /**
   * Transaction ID to reconcile.
   * @var string|null
   */
  protected ?string $trxnId = NULL;

  /**
   * Dry run mode (preview without applying changes).
   * @var bool
   */
  protected bool $dryRun = TRUE;

  /**
   * Determine reconciliation action based on CiviCRM status and Monetico bank status.
   *
   * @param string $currentStatus
   * @param string $bankState
   * @param float $recreditsTotal
   * @param float $capturedAmount
   *
   * @return array{target_status: string, action_taken: string}
   */
  public static function determineDiscrepancyAction(string $currentStatus, string $bankState, float $recreditsTotal, float $capturedAmount): array {
    $actionTaken = 'none';
    $newStatus = $currentStatus;

    if ($bankState === 'PA' && $capturedAmount > 0.0) {
      if ($recreditsTotal >= $capturedAmount) {
        if ($currentStatus === 'Completed') {
          $newStatus = 'Refunded';
          $actionTaken = 'marked_refunded';
        }
        else {
          // Bank shows refunded, but Civi is still Pending: DO NOT complete transaction
          $actionTaken = 'bank_refunded_while_civi_pending_requires_attention';
        }
      }
      elseif ($recreditsTotal > 0 && $recreditsTotal < $capturedAmount) {
        $actionTaken = 'partial_refund_requires_manual_attention';
      }
      elseif ($currentStatus === 'Pending') {
        $newStatus = 'Completed';
        $actionTaken = 'completed_transaction';
      }
      elseif ($currentStatus === 'Refunded' && $recreditsTotal < $capturedAmount) {
        $actionTaken = 'civi_refunded_while_bank_not_recredited_requires_attention';
      }
    }
    elseif ($bankState === 'PA' && $capturedAmount <= 0.0) {
      $actionTaken = 'uncaptured_payment_requires_attention';
    }
    elseif (in_array($bankState, array('AN', 'RE', 'GR', 'AP'), TRUE) && $currentStatus === 'Pending') {
      $newStatus = $bankState === 'AN' ? 'Cancelled' : 'Failed';
      $actionTaken = 'marked_failed_or_cancelled';
    }

    return [
      'target_status' => $newStatus,
      'action_taken' => $actionTaken,
    ];
  }

  /**
   * Build array values for creating a negative refund payment via API v3.
   *
   * @param int $contributionId
   * @param float $totalAmount
   * @param string $currency
   * @param int $processorId
   * @param int $originalPaymentId
   *
   * @return array
   */
  public static function buildRefundPaymentValues(int $contributionId, float $totalAmount, string $currency, int $processorId, int $originalPaymentId): array {
    return [
      'contribution_id' => $contributionId,
      'total_amount' => -$totalAmount,
      'currency' => $currency,
      'payment_processor_id' => $processorId,
      'cancelled_payment_id' => $originalPaymentId,
      'trxn_id' => 'REFUND-' . $contributionId,
    ];
  }

  public function _run(Result $result): void {
    $contributionId = $this->contributionId;

    if (!$contributionId && !empty($this->trxnId)) {
      $payments = \Civi\Api4\Payment::get(FALSE)
        ->addSelect('contribution_id')
        ->addWhere('trxn_id', '=', $this->trxnId)
        ->addWhere('total_amount', '>', 0)
        ->addJoin('PaymentProcessor AS processor', 'INNER', ['payment_processor_id', '=', 'processor.id'])
        ->addWhere('processor.class_name', '=', 'Payment_Cmcic')
        ->execute();
      if (count($payments) > 1) {
        throw new CRM_Core_Exception("Multiple Monetico payments found matching trxn_id '{$this->trxnId}'. Reconciliation requires an explicit contribution_id.");
      }
      if (count($payments) === 1) {
        $contributionId = (int) $payments->first()['contribution_id'];
      }
    }

    if (!$contributionId) {
      throw new CRM_Core_Exception('Contribution ID or valid trxn_id for a Monetico payment is required for reconciliation.');
    }

    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id', 'total_amount', 'currency', 'receive_date', 'is_test', 'contribution_status_id:name')
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->single();

    $payments = \Civi\Api4\Payment::get(FALSE)
      ->addSelect('id', 'payment_processor_id')
      ->addWhere('contribution_id', '=', $contributionId)
      ->addWhere('total_amount', '>', 0)
      ->addJoin('PaymentProcessor AS processor', 'INNER', ['payment_processor_id', '=', 'processor.id'])
      ->addWhere('processor.class_name', '=', 'Payment_Cmcic')
      ->execute();

    if (count($payments) > 1) {
      throw new CRM_Core_Exception("Multiple Monetico payment entries found for contribution #{$contributionId}.");
    }
    if (count($payments) === 0) {
      throw new CRM_Core_Exception("Contribution #{$contributionId} has no associated Monetico payment processor entry.");
    }

    $originalPayment = $payments->first();
    $processorId = (int) $originalPayment['payment_processor_id'];
    $originalPaymentId = (int) $originalPayment['id'];

    $isTest = !empty($contribution['is_test']);
    $initialProcessor = \Civi\Payment\System::singleton()->getById($processorId);
    if (!($initialProcessor instanceof \CRM_Core_Payment_Cmcic)) {
      throw new CRM_Core_Exception("Payment processor #{$processorId} is not a Monetico Payment_Cmcic processor.");
    }

    $processor = $initialProcessor->getOperationalProcessor($isTest ? 'test' : 'live');

    $totalAmount = (float) ($contribution['total_amount'] ?? 0);
    $currency = strtoupper((string) ($contribution['currency'] ?? 'EUR'));
    $currentStatus = (string) ($contribution['contribution_status_id:name'] ?? 'Pending');
    $receiveDate = strtotime((string) ($contribution['receive_date'] ?? 'now'));

    $endpoint = CRM_Core_Payment_CmcicPaymentStatus::getEndpoint($isTest);
    $statusResult = CRM_Core_Payment_CmcicPaymentStatus::query(
      array(
        'version' => '2.0',
        'TPE' => (string) $processor->getPaymentProcessor()['user_name'],
        'date' => date('d/m/Y', $receiveDate ?: time()),
        'montant' => number_format($totalAmount, 2, '.', '') . $currency,
        'reference' => (string) $contributionId,
        'societe' => (string) $processor->getPaymentProcessor()['signature'],
      ),
      $processor->getKey(),
      $processor->getAlgorithm(),
      $endpoint
    );

    $bankState = (string) ($statusResult['state'] ?? '');
    $recreditsTotal = (float) ($statusResult['recredits_total'] ?? 0.0);
    $capturedAmount = (float) ($statusResult['captured_amount'] ?? 0.0);

    $decision = self::determineDiscrepancyAction($currentStatus, $bankState, $recreditsTotal, $capturedAmount);
    $actionTaken = $decision['action_taken'];
    $newStatus = $decision['target_status'];

    $outcome = [
      'contribution_id' => $contributionId,
      'current_status' => $currentStatus,
      'bank_state' => $bankState,
      'recredits_total' => $recreditsTotal,
      'target_status' => $newStatus,
      'action_taken' => $actionTaken,
      'dry_run' => $this->dryRun,
    ];

    if (!$this->dryRun && $actionTaken !== 'none' && !str_ends_with($actionTaken, '_requires_attention')) {
      if ($actionTaken === 'completed_transaction') {
        $trxnId = $contributionId . '-' . ($statusResult['authorization_number'] ?: 'status');
        civicrm_api3('contribution', 'completetransaction', array(
          'id' => $contributionId,
          'trxn_id' => $trxnId,
          'payment_processor_id' => $processorId,
        ));
      }
      elseif ($actionTaken === 'marked_refunded') {
        $refundTrxnId = 'REFUND-' . $contributionId;
        $existingRefundPayment = \Civi\Api4\Payment::get(FALSE)
          ->addWhere('contribution_id', '=', $contributionId)
          ->addWhere('payment_processor_id', '=', $processorId)
          ->addWhere('trxn_id', '=', $refundTrxnId)
          ->execute()
          ->first();

        if (!$existingRefundPayment) {
          $refundValues = self::buildRefundPaymentValues(
            $contributionId,
            $totalAmount,
            $currency,
            $processorId,
            $originalPaymentId
          );
          civicrm_api3('Payment', 'create', $refundValues);
        }

        \Civi\Api4\Contribution::update(FALSE)
          ->setValues([
            'contribution_status_id:name' => 'Refunded',
            'cancel_date' => 'now',
          ])
          ->addWhere('id', '=', $contributionId)
          ->execute();
      }
      elseif ($actionTaken === 'marked_failed_or_cancelled') {
        \Civi\Api4\Contribution::update(FALSE)
          ->setValues([
            'contribution_status_id:name' => $newStatus,
            'cancel_date' => 'now',
          ])
          ->addWhere('id', '=', $contributionId)
          ->execute();
      }
    }

    $result[] = $outcome;
  }

}
