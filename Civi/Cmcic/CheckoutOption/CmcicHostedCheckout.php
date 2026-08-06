<?php

namespace Civi\Cmcic\CheckoutOption;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Checkout\AfformCheckoutOptionInterface;
use Civi\Checkout\CheckoutOptionInterface;
use Civi\Checkout\CheckoutSession;

if (interface_exists('Civi\Checkout\CheckoutOptionInterface') && interface_exists('Civi\Checkout\AfformCheckoutOptionInterface')) {

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
      $session->setCheckoutParam('cmcic_return', 'ok');
      $successURL = $session->getLandingUrl();
      $session->setCheckoutParam('cmcic_return', 'err');
      $failureURL = $session->getLandingUrl();
      $session->setCheckoutParam('cmcic_return', NULL);
      $session->setResponseItem('redirect', $processor->startHostedCheckoutForContribution(
        $session->getContributionId(),
        $successURL,
        $failureURL
      ));
    }

    public function continueCheckout(CheckoutSession $session): void {
      try {
        $connection = $this->getConnectionDetails($session->isTestMode());
        $processor = \Civi\Payment\System::singleton()->getByName(
          $connection['name'],
          $session->isTestMode()
        );
        $checkoutStatus = $processor->synchronizeHostedCheckoutContribution($session->getContributionId());
      }
      catch (\Throwable $e) {
        \Civi::log()->warning('Unable to retrieve the Monetico payment status: ' . $e->getMessage());
        $session->pending();
        return;
      }

      if ($checkoutStatus === 'success') {
        $session->success();
        return;
      }
      if ($checkoutStatus === 'cancel') {
        $session->cancel();
        return;
      }
      if ($checkoutStatus === 'fail') {
        $session->fail();
        return;
      }
      if ($session->getCheckoutParam('cmcic_return') === 'err') {
        // The signed return token came from url_retour_err. A bank state of PA
        // still wins above; otherwise the customer has abandoned or refused it.
        $processor->cancelHostedCheckoutContribution($session->getContributionId());
        $session->cancel();
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

}
