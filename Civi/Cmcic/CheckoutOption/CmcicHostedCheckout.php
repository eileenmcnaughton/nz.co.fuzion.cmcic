<?php

namespace Civi\Cmcic\CheckoutOption;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Checkout\AfformCheckoutOptionInterface;
use Civi\Checkout\CheckoutOptionInterface;
use Civi\Checkout\CheckoutSession;

/**
 * Expose the existing Monetico hosted checkout to Afform/Form Builder.
 */
class CmcicHostedCheckout implements CheckoutOptionInterface, AfformCheckoutOptionInterface {

  protected $liveConnection;

  protected $testConnection;

  public function __construct($liveConnection, $testConnection) {
    $this->liveConnection = $liveConnection;
    $this->testConnection = $testConnection;
  }

  public function getLabel(): string {
    return (string) ($this->getConnectionDetails(FALSE)['title'] ?? 'Monetico');
  }

  public function getFrontendLabel(): string {
    $connection = $this->getConnectionDetails(FALSE);
    return (string) ($connection['frontend_title'] ?? $connection['title'] ?? 'Monetico');
  }

  public function getPaymentMethod(): ?string {
    return NULL;
  }

  public function getPaymentProcessorId(): ?int {
    $connection = $this->liveConnection ?: $this->testConnection;
    return !empty($connection['id']) ? (int) $connection['id'] : NULL;
  }

  public function validate(AfformValidateEvent $event): void {
    // Monetico validates payment on its hosted page.
  }

  public function getAfformSettings(bool $testMode): array {
    return array(
      'description' => ts('You will be redirected to Monetico to complete your payment.'),
    );
  }

  public function getAfformModule(): ?string {
    return NULL;
  }

  public function startCheckout(CheckoutSession $session): void {
    $connection = $this->getConnectionDetails($session->isTestMode());
    $processor = \Civi\Payment\System::singleton()->getByName(
      $connection['name'],
      $session->isTestMode()
    );
    $session->setResponseItem('redirect', $processor->startHostedCheckoutForContribution(
      $session->getContributionId(),
      $session->getLandingUrl()
    ));
  }

  public function continueCheckout(CheckoutSession $session): void {
    // The IPN, rather than an untrusted browser return, finalizes the payment.
    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name')
      ->addWhere('id', '=', $session->getContributionId())
      ->execute()
      ->first();

    switch ($contribution['contribution_status_id:name'] ?? NULL) {
      case 'Completed':
        $session->success();
        return;

      case 'Cancelled':
        $session->cancel();
        return;

      case 'Failed':
        $session->fail();
        return;
    }

    $session->pending();
  }

  protected function getConnectionDetails(bool $testMode): array {
    $connection = $testMode ? $this->testConnection : $this->liveConnection;
    if (!$connection) {
      throw new \CRM_Core_Exception(ts('No active Monetico payment processor is available for this mode.'));
    }
    return $connection;
  }

}
