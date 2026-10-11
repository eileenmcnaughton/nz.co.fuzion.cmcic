<?php

/**
 * Browser page hosting the PCI-isolated Monetico card fields and 3DS steps.
 */
class CRM_Cmcic_Page_HostedFields extends CRM_Core_Page {
  private const SIGNED_FIELDS = ['attempt_id', 'expires', 'processor_id'];

  public function run() {
    try {
      $params = $this->signedParameters();
      $attempt = CRM_Cmcic_HostedFieldsStore::get($params['attempt_id']);
      if ((int) $attempt['processor_id'] !== $params['processor_id']) {
        throw new CRM_Core_Exception(ts('The Monetico payment attempt is inconsistent.'));
      }
      $processor = \Civi\Payment\System::singleton()->getById($params['processor_id']);
      if (!$processor instanceof CRM_Core_Payment_Cmcic) {
        throw new CRM_Core_Exception(ts('Unable to load the Monetico payment processor.'));
      }

      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = $this->continueOrSubmit($processor, $attempt);
        $attempt = $this->applyResult($processor, $params['attempt_id'], $attempt, $result);
      }

      $this->renderAttempt($processor, $attempt);
    }
    catch (Throwable $e) {
      Civi::log()->error('Monetico Hosted Fields checkout failed: ' . $e->getMessage());
      $this->assign('paymentError', ts('Unable to process the Monetico payment: %1', [1 => $e->getMessage()]));
    }
    parent::run();
  }

  /**
   * @return array{attempt_id: string, expires: int, processor_id: int} */
  private function signedParameters(): array {
    $params = [
      'attempt_id' => (string) CRM_Utils_Request::retrieve('attempt_id', 'String', $this, TRUE),
      'expires' => (int) CRM_Utils_Request::retrieve('expires', 'Positive', $this, TRUE),
      'processor_id' => (int) CRM_Utils_Request::retrieve('processor_id', 'Positive', $this, TRUE),
    ];
    $signature = (string) CRM_Utils_Request::retrieve('_sgn', 'String', $this, TRUE);
    $signer = new CRM_Utils_Signer(
        CRM_Core_Payment_Cmcic::getHostedFieldsSigningKey(),
        self::SIGNED_FIELDS
    );
    if ($params['expires'] < time() || !$signer->validate($signature, $params)) {
      throw new CRM_Core_Exception(ts('The Monetico payment link is invalid or expired.'));
    }
    return $params;
  }

  /**
   * @return array<string, mixed> */
  private function continueOrSubmit(CRM_Core_Payment_Cmcic $processor, array $attempt): array {
    if (!empty($_POST['cres'])) {
      $details = ['cres' => (string) $_POST['cres']];
      if (!empty($_POST['threeDSSessionData'])) {
        $details['threeDSSessionData'] = (string) $_POST['threeDSSessionData'];
      }
      return CRM_Core_Payment_CmcicHostedFieldsClient::continuePayment($processor, [
        'payment_token' => (string) $attempt['payment_token'],
        'authentication' => ['details' => $details],
      ]);
    }
    if (!empty($_POST['technical_complete'])) {
      return CRM_Core_Payment_CmcicHostedFieldsClient::continuePayment($processor, [
        'payment_token' => (string) $attempt['payment_token'],
        'authentication' => ['status' => 'threedsmethod_requested'],
      ]);
    }
    $attempt['context']['browser'] = $this->browserContext();
    return CRM_Core_Payment_CmcicHostedFieldsClient::requestPayment($processor, $attempt);
  }

  /**
   * @return array<string, bool|int|string> */
  private function browserContext(): array {
    try {
      $provided = json_decode(
        (string) ($_POST['browser_info'] ?? ''),
        TRUE,
        16,
        JSON_THROW_ON_ERROR
      );
    }
    catch (JsonException) {
      throw new CRM_Core_Exception(ts('The browser information required for 3-D Secure is invalid.'));
    }
    if (!is_array($provided)) {
      throw new CRM_Core_Exception(ts('The browser information required for 3-D Secure is missing.'));
    }
    $language = substr((string) ($provided['language'] ?? ''), 0, 35);
    $colorDepth = (int) ($provided['color_depth'] ?? 0);
    $screenHeight = (int) ($provided['screen_height'] ?? 0);
    $screenWidth = (int) ($provided['screen_width'] ?? 0);
    $timezone = (int) ($provided['timezone'] ?? 0);
    if (
        $language === ''
        || $colorDepth <= 0
        || $screenHeight <= 0
        || $screenWidth <= 0
        || abs($timezone) > 1440
    ) {
      throw new CRM_Core_Exception(ts('The browser information required for 3-D Secure is incomplete.'));
    }
    return [
      'accept_header' => substr((string) ($_SERVER['HTTP_ACCEPT'] ?? '*/*'), 0, 2048),
      'java_enabled' => !empty($provided['java_enabled']),
      'language' => $language,
      'color_depth' => $colorDepth,
      'screen_height' => $screenHeight,
      'screen_width' => $screenWidth,
      'timezone' => $timezone,
      'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 2048),
    ];
  }

  /**
   * @return array<string, mixed> */
  private function applyResult(
        CRM_Core_Payment_Cmcic $processor,
        string $attemptId,
        array $attempt,
        array $result
    ): array {
    $returnCode = (int) ($result['return_code'] ?? -1);
    $paymentStatus = (string) ($result['payment']['status'] ?? '');
    if ($returnCode === 1 && $paymentStatus === 'captured') {
      $authorization = (string) ($result['payment']['authorisation']['number'] ?? 'hostedfields');
      $transactionId = (string) $attempt['contribution_id'] . '-' . $authorization;
      if (!empty($processor->getPaymentProcessor()['is_test'])) {
        $transactionId = 'test' . $transactionId;
      }
      civicrm_api3('contribution', 'completetransaction', [
        'id' => (int) $attempt['contribution_id'],
        'trxn_id' => $transactionId,
        'payment_processor_id' => (int) $attempt['processor_id'],
      ]);
      CRM_Cmcic_HostedFieldsStore::delete($attemptId);
      CRM_Utils_System::redirect((string) $attempt['return_url']);
    }
    if ($returnCode === 2 && !empty($result['next_step']) && !empty($result['payment_token'])) {
      $attempt['payment_token'] = (string) $result['payment_token'];
      $attempt['next_step'] = $this->normaliseNextStep((array) $result['next_step']);
      CRM_Cmcic_HostedFieldsStore::set($attemptId, $attempt);
      return $attempt;
    }

    $message = (string) (
        $result['payment']['refusal_reason']
        ?? $result['error_message']
        ?? $paymentStatus
        ?: 'unexpected response'
    );
    throw new CRM_Core_Exception(ts('Monetico did not capture the payment: %1', [1 => $message]));
  }

  /**
   * @return array{step: string, url: string, data: array<string, string>} */
  private function normaliseNextStep(array $nextStep): array {
    $step = (string) ($nextStep['step'] ?? '');
    $url = trim((string) ($nextStep['url'] ?? ''));
    if (!in_array($step, ['technical_information_collecting', 'cardholder_authentication'], TRUE)) {
      throw new CRM_Core_Exception(ts('Monetico returned an unsupported authentication step.'));
    }
    if (!str_starts_with($url, 'https://')) {
      throw new CRM_Core_Exception(ts('Monetico returned an invalid authentication URL.'));
    }
    $data = [];
    foreach ((array) ($nextStep['data'] ?? []) as $name => $value) {
      if (is_scalar($value)) {
        $data[(string) $name] = (string) $value;
      }
    }
    return ['step' => $step, 'url' => $url, 'data' => $data];
  }

  private function renderAttempt(CRM_Core_Payment_Cmcic $processor, array $attempt): void {
    $isTest = !empty($processor->getPaymentProcessor()['is_test']);
    if (empty($attempt['next_step'])) {
      CRM_Core_Resources::singleton()
        ->addScriptUrl(CRM_Core_Payment_CmcicHostedFieldsClient::getSdkUrl($isTest))
        ->addVars('cmcicHostedFields', [
          'pointOfSale' => (string) $attempt['point_of_sale'],
          'token' => (string) $attempt['payment_mean_token'],
        ])
        ->addScriptFile('nz.co.fuzion.cmcic', 'js/cmcicHostedFields.js');
    }
    $this->assign('amount', CRM_Utils_Money::format($attempt['amount_minor'] / 100, $attempt['currency']));
    $this->assign('cancelUrl', $attempt['cancel_url']);
    $this->assign('isTest', $isTest);
    $this->assign('nextStep', $attempt['next_step'] ?? NULL);
  }

}
