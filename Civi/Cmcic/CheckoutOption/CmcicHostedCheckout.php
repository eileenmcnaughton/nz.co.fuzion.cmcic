<?php

namespace Civi\Cmcic\CheckoutOption;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Checkout\AfformCheckoutOptionInterface;
use Civi\Checkout\CheckoutOptionInterface;
use Civi\Checkout\CheckoutSession;

if (
    interface_exists('Civi\Checkout\CheckoutOptionInterface')
    && interface_exists('Civi\Checkout\AfformCheckoutOptionInterface')
) {

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
      return (string) ($this->getDisplayConnection()['title'] ?? 'Monetico');
    }

    public function getFrontendLabel(): string {
      $connection = $this->getDisplayConnection();
      return (string) ($connection['frontend_title'] ?? $connection['title'] ?? 'Monetico');
    }

    public function getPaymentMethod(): ?string {
      $connection = $this->getDisplayConnection();
      if (empty($connection['payment_instrument_id'])) {
        return NULL;
      }

      $instrument = \Civi\Api4\OptionValue::get(FALSE)
        ->addSelect('name')
        ->addWhere('option_group_id:name', '=', 'payment_instrument')
        ->addWhere('value', '=', (int) $connection['payment_instrument_id'])
        ->execute()
        ->first();

      return !empty($instrument['name']) ? (string) $instrument['name'] : NULL;
    }

    public function getPaymentProcessorId(bool $testMode = FALSE): ?int {
      $connection = $testMode ? $this->testConnection : $this->liveConnection;
      return !empty($connection['id']) ? (int) $connection['id'] : NULL;
    }

    public function validate(AfformValidateEvent $event): void {
      // Monetico validates payment on its hosted page.
    }

    public function getAfformSettings(bool $testMode): array {
      return [
        'description' => ts('You will be redirected to Monetico to complete your payment.'),
      ];
    }

    public function getAfformModule(): ?string {
      return NULL;
    }

    public function startCheckout(CheckoutSession $session): void {
      $processor = $this->getProcessor($session);
      $session->setCheckoutParam('cmcic_return', 'success');
      $successURL = $session->getLandingUrl();
      $session->setCheckoutParam('cmcic_return', 'cancel');
      $failureURL = $session->getLandingUrl();
      $session->setCheckoutParam('cmcic_return', NULL);
      $session->setResponseItem('redirect', $processor->startCheckoutForContribution(
        $session->getContributionId(),
        $successURL,
        $failureURL
      ));
    }

    public function continueCheckout(CheckoutSession $session): void {
      try {
        $processor = $this->getProcessor($session);
        $checkoutStatus = $processor->synchronizeHostedCheckoutContribution($session->getContributionId());
      }
      catch (\Throwable $e) {
        \Civi::log()->warning('Unable to retrieve the Monetico payment status: ' . $e->getMessage());
        // Do not overwrite the restored pending session while an IPN may be
        // completing the contribution in another request.
        return;
      }

      if ($checkoutStatus === 'success') {
        $session->success();
        return;
      }
      if ($checkoutStatus === 'cancel') {
        // Monetico confirmed the terminal bank state, so persist cancellation.
        $session->cancel();
        return;
      }
      if ($checkoutStatus === 'fail') {
        $session->fail();
        return;
      }
      if ($session->getCheckoutParam('cmcic_return') === 'cancel') {
        // The browser return is only UI state. Keep the contribution Pending
        // until the Monetico IPN or a later EtatPaiement reconciliation decides
        // its accounting status.
        // This is only the signed browser journey. Cancel the Afform UX while
        // leaving the contribution Pending for IPN or EtatPaiement.
        $session->setStatus(CheckoutSession::STATUS_CANCEL);
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

    protected function getDisplayConnection(): array {
      $connection = $this->liveConnection ?: $this->testConnection;
      if (!$connection) {
        throw new \CRM_Core_Exception(ts('No active Monetico payment processor is available.'));
      }
      return $connection;
    }

    protected function getProcessor(CheckoutSession $session): \CRM_Core_Payment_Cmcic {
      $connection = $this->getConnectionDetails($session->isTestMode());
      $processor = \Civi\Payment\System::singleton()->getByName(
        (string) $connection['name'],
        $session->isTestMode()
      );
      if (!$processor instanceof \CRM_Core_Payment_Cmcic) {
        throw new \CRM_Core_Exception(ts('Unable to load the Monetico payment processor.'));
      }
      return $processor;
    }

  }

}
