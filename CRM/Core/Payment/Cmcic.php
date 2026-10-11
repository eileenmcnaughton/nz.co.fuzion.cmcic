<?php

class CRM_Core_Payment_Cmcic extends CRM_Core_Payment {
  public const CHARSET = 'iso-8859-1';

  protected $_mode = NULL;

  protected $_key = '';

  protected $_algorithm = 'sha1';
  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  private static $_singleton = NULL;

  /**
   * Constructor
   *
   * @param string $mode The mode of operation: live or test.
   * @param mixed $paymentProcessor Payment processor configuration.
   *
   * @return void
   */
  public function __construct($mode, &$paymentProcessor) {

    $this->_mode = $mode;
    $this->_key = $paymentProcessor['password'];
    $this->_algorithm = empty($paymentProcessor['subject']) ? 'sha1' : $paymentProcessor['subject'];

    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('CMCIC');
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode The mode of operation: live or test.
   * @param mixed $paymentProcessor Payment processor configuration.
   * @param mixed $paymentForm Payment form, when available.
   * @param mixed $force Whether to force a new instance.
   *
   * @return object
   * @static
   *
   */
  public static function &singleton($mode = 'test', &$paymentProcessor = NULL, &$paymentForm = NULL, $force = FALSE) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new CRM_Core_Payment_Cmcic($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  public function checkConfig() {
    $config = CRM_Core_Config::singleton();

    $error = [];

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts(
        'POS terminal number is not set in the Administer &raquo; System Settings &raquo; Payment Processors'
      );
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts(
        'Merchant security key is not set in the Administer &raquo; System Settings &raquo; Payment Processors'
      );
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  public function setessCheckOut(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  public function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * Main transaction function
   *
   * @param array $params Name value pair of contribution data.
   * @param string $component CiviCRM component name.
   *
   * @return void
   * @access public
   *
   */
  public function doPayment(&$params, $component = 'contribute') {
    $this->_component = strtolower($component);
    $contributionID = !empty($params['contributionID'])
            ? $params['contributionID']
            : (!empty($params['contribution_id']) ? $params['contribution_id'] : NULL);
    if (!$contributionID) {
      Civi::log()->error('Cannot start Monetico checkout without a contribution ID.', [
        'parameter_keys' => array_keys($params),
      ]);
      throw new CRM_Core_Exception(ts('Unable to prepare the payment reference.'));
    }

    $qfKey = $params['qfKey'] ?? NULL;
    $participantId = $params['participantID'] ?? $params['participant_id'] ?? NULL;

    $returnOKURL = !empty($params['returnURL']) ? $params['returnURL'] : $this->getReturnSuccessUrl($qfKey);
    $cancelURL = !empty($params['cancelURL']) ? $params['cancelURL'] : $this->getCancelUrl($qfKey, $participantId);

    if ($this->_component === 'event') {
      $merchantRef = ($params['contactID'] ?? '') . '-' . ($params['eventID'] ?? '');
    }
    elseif ($this->_component === 'contribute') {
      $merchantRef = ($params['contactID'] ?? '') . '-' . $contributionID;
    }
    else {
      $merchantRef = (string) $contributionID;
    }

    $relayUrl = $this->prepareCheckout($params, $returnOKURL, $cancelURL, $merchantRef);

    if (self::isDrupalAjaxRequest()) {
      if (class_exists('\\Drupal\\webform\\Ajax\\WebformRefreshCommand')) {
        $command = (new \Drupal\webform\Ajax\WebformRefreshCommand($relayUrl))->render();
        $command['paymentRedirect'] = TRUE;
        $command['paymentProvider'] = 'monetico';
        CRM_Core_Page_AJAX::returnJsonResponse([$command]);
      }

      $commands = [
            [
              'command' => 'cmcicRedirect',
              'url' => $relayUrl,
              'paymentRedirect' => TRUE,
              'paymentProvider' => 'monetico',
            ],
      ];
      CRM_Utils_JSON::output($commands);
    }

    CRM_Utils_System::redirect($relayUrl);
  }

  /**
   * Prepare the checkout selected for every CiviCRM form integration.
   *
   * @param array $params
   * @param string $returnOKURL
   * @param string $cancelURL
   * @param string $merchantRef
   * @param string|null $mode
   *
   * @return string
   */
  public function prepareCheckout($params, $returnOKURL, $cancelURL, $merchantRef, $mode = NULL) {
    $mode = $mode ?? CRM_Cmcic_CheckoutMode::getConfiguredMode();
    CRM_Cmcic_CheckoutMode::assertImplemented($mode);

    if ($mode === CRM_Cmcic_CheckoutMode::HOSTED_FIELDS) {
      return $this->prepareHostedFieldsCheckout($params, $returnOKURL, $cancelURL);
    }

    return $this->prepareHostedCheckout($params, $returnOKURL, $cancelURL, $merchantRef);
  }

  /**
   * Prepare the experimental Monetico Hosted Fields checkout.
   */
  public function prepareHostedFieldsCheckout($params, $returnOKURL, $cancelURL) {
    $contributionID = !empty($params['contributionID'])
            ? $params['contributionID']
            : ($params['contribution_id'] ?? NULL);
    if (!$contributionID) {
      throw new CRM_Core_Exception(ts('Unable to prepare the payment reference.'));
    }

    $contextEncoded = CRM_Core_Payment_CmcicOrderContext::buildFromPaymentParams($params);
    $context = json_decode(base64_decode($contextEncoded, TRUE), TRUE);
    if (!is_array($context)) {
      throw new CRM_Core_Exception(ts('Unable to prepare the Monetico order context.'));
    }

    $email = '';
    foreach (['email', 'email-Primary', 'email-5'] as $emailField) {
      if (!empty($params[$emailField])) {
        $email = (string) $params[$emailField];
        break;
      }
    }
    $contactID = !empty($params['contactID']) ? $params['contactID'] : ($params['contact_id'] ?? NULL);
    if (!$email && $contactID) {
      $contactEmail = \Civi\Api4\Email::get(FALSE)
        ->addSelect('email')
        ->addWhere('contact_id', '=', $contactID)
        ->addOrderBy('is_primary', 'DESC')
        ->execute()
        ->first();
      $email = (string) ($contactEmail['email'] ?? '');
    }
    if (!$email) {
      throw new CRM_Core_Exception(ts('An email address is required for Monetico Hosted Fields.'));
    }

    $attemptId = bin2hex(random_bytes(16));
    $expires = time() + 3600;
    $signedParams = [
      'attempt_id' => $attemptId,
      'expires' => $expires,
      'processor_id' => (int) $this->_paymentProcessor['id'],
    ];
    $signer = new CRM_Utils_Signer(self::getHostedFieldsSigningKey(), array_keys($signedParams));
    $signedParams['_sgn'] = $signer->sign($signedParams);
    $hostedFieldsUrl = CRM_Utils_System::url(
        'civicrm/cmcic/hosted-fields',
        $signedParams,
        TRUE,
        NULL,
        FALSE,
        TRUE
    );
    $token = CRM_Core_Payment_CmcicHostedFieldsClient::initializePaymentMean($this);
    CRM_Cmcic_HostedFieldsStore::set($attemptId, [
      'amount_minor' => (int) round((float) CRM_Utils_Rule::cleanMoney($params['amount'] ?? 0) * 100),
      'cancel_url' => $cancelURL,
      'context' => $context,
      'contribution_id' => (int) $contributionID,
      'currency' => strtoupper((string) ($params['currencyID'] ?? 'EUR')),
      'email' => $email,
      'expires' => $expires,
      'hosted_fields_url' => $hostedFieldsUrl,
      'payment_mean_token' => (string) $token['token'],
      'point_of_sale' => (string) $this->_paymentProcessor['user_name'],
      'processor_id' => (int) $this->_paymentProcessor['id'],
      'return_url' => $returnOKURL,
    ]);

    \Civi\Api4\Contribution::update(FALSE)
      ->addWhere('id', '=', (int) $contributionID)
      ->setValue('payment_processor_id', (int) $this->_paymentProcessor['id'])
      ->execute();

    return $hostedFieldsUrl;
  }

  public static function getHostedFieldsSigningKey(): string {
    $siteKey = defined('CIVICRM_SITE_KEY') ? (string) constant('CIVICRM_SITE_KEY') : '';
    if ($siteKey === '') {
      throw new CRM_Core_Exception(ts('The CiviCRM site key is not configured.'));
    }
    return hash_hmac('sha256', 'cmcic-hosted-fields-v1', $siteKey);
  }

  /**
   * Check if current request is a Drupal AJAX request.
   *
   * @return bool
   */
  public static function isDrupalAjaxRequest(): bool {
    return self::isDrupalWebformAjaxRequest()
        || !empty($_REQUEST['ajax_form'])
        || (isset($_REQUEST['_wrapper_format']) && $_REQUEST['_wrapper_format'] === 'drupal_ajax');
  }

  /**
   * Check whether the request is a Drupal Webform AJAX submission.
   *
   * @return bool
   */
  public static function isDrupalWebformAjaxRequest(): bool {
    return !empty($_REQUEST['_drupal_ajax']);
  }

  /**
   * Prepare the existing Monetico POST checkout and return its local relay URL.
   *
   * @param array $params
   * @param string $returnOKURL
   * @param string $cancelURL
   * @param string $merchantRef
   *
   * @return string
   */
  public function prepareHostedCheckout($params, $returnOKURL, $cancelURL, $merchantRef) {
    $contributionID = !empty($params['contributionID'])
            ? $params['contributionID']
            : (!empty($params['contribution_id']) ? $params['contribution_id'] : NULL);
    if (!$contributionID) {
      throw new CRM_Core_Exception(ts('Unable to prepare the payment reference.'));
    }

    $emailFields  = ['email', 'email-Primary', 'email-5'];
    $email = '';
    foreach ($emailFields as $emailField) {
      if (!empty($params[$emailField])) {
        $email = $params[$emailField];
      }
    }
    $lang = $this->getLanguage();

    $cleanAmount = CRM_Utils_Rule::cleanMoney($params['amount'] ?? 0);
    $formattedAmount = number_format((float) $cleanAmount, 2, '.', '');

    $paymentParams = [
      'TPE' => $this->_paymentProcessor['user_name'],
      'contexte_commande' => CRM_Core_Payment_CmcicOrderContext::buildFromPaymentParams($params),
      'date' => date("d/m/Y:H:i:s"),
      'lgue' => $lang,
      'mail' => $email,
      'montant' => $formattedAmount . $params['currencyID'],
      'reference' => $contributionID,
      'societe' => $this->_paymentProcessor['signature'],
      'texte-libre' => $this->urlEncodeField($merchantRef, 24),
      'url_retour_ok' => $returnOKURL,
      'url_retour_err' => $cancelURL,
      'version' => '3.0',
    ];

    // Allow further manipulation of params via custom hooks
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $paymentParams);
    $paymentParams['MAC'] = CRM_Core_Payment_CmcicHmac::calculate(
        $paymentParams,
        $this->getKey(),
        $this->getAlgorithm()
    );

    CRM_Core_Session::singleton()->set('checkout', [
      'fields' => $paymentParams,
      'url' => $this->_paymentProcessor['url_site'],
    ], 'cmcic');
    return CRM_Utils_System::url('civicrm/cmcic', ['reset' => 1]);
  }

  /**
   * Prepare a hosted checkout for a contribution created by CiviCRM Checkout.
   *
   * @param int $contributionID
   * @param array|string $urls
   * @param string|null $failureURL
   *
   * @return string
   */
  public function startHostedCheckoutForContribution($contributionID, $urls = [], $failureURL = NULL) {
    return $this->startCheckoutForContribution(
        $contributionID,
        $urls,
        $failureURL,
        CRM_Cmcic_CheckoutMode::HOSTED_PAGE
    );
  }

  /**
   * Start the configured checkout for a contribution created by Checkout.
   *
   * @param int $contributionID
   * @param array|string $urls
   * @param string|null $failureURL
   * @param string|null $mode
   *
   * @return string
   */
  public function startCheckoutForContribution($contributionID, $urls = [], $failureURL = NULL, $mode = NULL) {
    if (is_string($urls)) {
      $urls = [
        'return_url' => $urls,
        'cancel_url' => $failureURL ?? $urls,
      ];
    }

    $returnOKURL = $urls['return_url'] ?? ($urls['landing_url'] ?? '');
    $cancelURL = $urls['cancel_url'] ?? ($urls['landing_url'] ?? '');

    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('contact_id', 'total_amount', 'currency')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->single();

    $params = [
      'contributionID' => $contributionID,
      'contactID' => $contribution['contact_id'],
      'amount' => $contribution['total_amount'],
      'currencyID' => $contribution['currency'],
    ];
    $merchantRef = $params['contactID'] . '-' . $contributionID;

    return $this->prepareCheckout(
        $params,
        $returnOKURL,
        $cancelURL,
        $merchantRef,
        $mode
    );
  }

  /**
   * Query Monetico and reconcile a pending hosted checkout contribution.
   *
   * @param int $contributionID
   *
   * @return string CiviCRM Checkout status
   */
  public function synchronizeHostedCheckoutContribution($contributionID) {
    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('total_amount', 'currency', 'receive_date', 'contribution_status_id:name')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->single();

    $existingStatus = $contribution['contribution_status_id:name'];
    if ($existingStatus === 'Completed') {
      return 'success';
    }
    if ($existingStatus === 'Cancelled') {
      return 'cancel';
    }
    if ($existingStatus === 'Failed') {
      return 'fail';
    }

    $orderDate = strtotime($contribution['receive_date']);
    if (!$orderDate) {
      throw new CRM_Core_Exception('Unable to determine the Monetico payment date.');
    }

    $paymentStatus = CRM_Core_Payment_CmcicPaymentStatus::query(
        [
          'version' => '2.0',
          'TPE' => $this->_paymentProcessor['user_name'],
          'date' => date('d/m/Y', $orderDate),
          'montant' => number_format(
            (float) CRM_Utils_Rule::cleanMoney($contribution['total_amount']),
            2,
            '.',
            ''
          ) . $contribution['currency'],
          'reference' => (string) $contributionID,
          'societe' => $this->_paymentProcessor['signature'],
        ],
        $this->getKey(),
        $this->getAlgorithm(),
        CRM_Core_Payment_CmcicPaymentStatus::getEndpoint($this->_mode === 'test')
    );
    $checkoutStatus = CRM_Core_Payment_CmcicPaymentStatus::getCheckoutStatus($paymentStatus['state']);

    if ($checkoutStatus === 'success') {
      $trxnId = $contributionID . '-' . ($paymentStatus['authorization_number'] ?: 'status');
      if ($this->_mode === 'test') {
        $trxnId = 'test' . $contributionID . uniqid();
      }
      civicrm_api3('contribution', 'completetransaction', [
        'id' => $contributionID,
        'trxn_id' => $trxnId,
        'payment_processor_id' => $this->_paymentProcessor['id'],
      ]);
    }
    elseif ($checkoutStatus === 'cancel' || $checkoutStatus === 'fail') {
      $this->setHostedCheckoutContributionStatus(
        $contributionID,
        $checkoutStatus === 'cancel' ? 'Cancelled' : 'Failed'
        );
    }

    return $checkoutStatus;
  }

  /**
   * Cancel a hosted checkout after a signed error return.
   *
   * @param int $contributionID
   */
  public function cancelHostedCheckoutContribution($contributionID) {
    $this->setHostedCheckoutContributionStatus($contributionID, 'Cancelled');
  }

  /**
   * Persist a terminal checkout status with the processor financial account.
   *
   * @param int $contributionID
   * @param string $status
   */
  public function setHostedCheckoutContributionStatus($contributionID, $status) {
    \Civi\Api4\Contribution::update(FALSE)->setValues([
      'contribution_status_id:name' => $status,
      'cancel_date' => 'now',
      'payment_processor_id' => $this->_paymentProcessor['id'],
    ])->addWhere('id', '=', $contributionID)->execute();
  }

  /**
   * Cut field to correct length without truncating mid character
   * @param string $value
   * @param integer $fieldlength
   * @return string
   */
  public function urlEncodeField($value, $fieldlength) {
    //@todo - we need to do more testing about the encoding - at this stage we have stopped
    // passing description strings until we can sort
    return substr($value, 0, $fieldlength);

    /**
       * $string = substr(rawurlencode($value), 0, $fieldlength);
     * $lastPercent = strrpos($string, '%');
     * if ($lastPercent > $fieldlength - 3) {
     * $string = substr($string, 0, $lastPercent);
     * }
     * return $string;
      */
  }

  /**
   * format key - adapted from drupal commerce module
   * @param unknown $key
   * @return string
   */
  private function getUsableKey($key) {
    $hex_str_key  = substr($key, 0, 38);
    $hex_final   = "" . substr($key, 38, 2) . "00";

    $cca0 = ord($hex_final);

    if ($cca0 > 70 && $cca0 < 97) {
      $hex_str_key .= chr($cca0 - 23) . substr($hex_final, 1, 1);
    }
    else {
      if (substr($hex_final, 1, 1) == "M") {
        $hex_str_key .= substr($hex_final, 0, 1) . "0";
      }
      else {
        $hex_str_key .= substr($hex_final, 0, 2);
      }
    }
    return pack("H*", $hex_str_key);
  }

  /**
   * Get language string -Size: 2 characters
   * Possible values: FR EN DE IT ES NL PT SV
   * Since this is a French payment processor we will default to French if no
   * other match established
   * @return string
   */
  public function getLanguage() {
    global $tsLocale;
    $lang = substr($tsLocale, 0, 2);
    $validLangs = ['fr', 'en', 'de', 'it', 'es', 'nl', 'pt', 'sv'];
    if (in_array($lang, $validLangs)) {
      return strtoupper($lang);
    }
    return 'FR';
  }

  /**
   * getter for key
   * @return string
   */
  public function getKey() {
    return $this->getUsableKey($this->_key);
  }

  /**
   * getter for algorithm
   * @return string
   */
  public function getAlgorithm() {
    return $this->_algorithm;
  }

  public function handlePaymentNotification(): void {
    // Prefer official Monetico HTTP POST notifications, falling back to $_GET only for manual replay
    $inputData = !empty($_POST) ? $_POST : $_GET;
    $ipn = new CRM_Core_Payment_CmcicIPN(array_merge($inputData, ['exit_mode' => TRUE]));
    $ipn->main($this->_paymentProcessor);

    // If for any reason we come back here
    CRM_Core_Error::debug_log_message("It should not be possible to reach this line");
  }

  /**
   * Declare refund capability based on setting.
   *
   * @return bool
   */
  public function supportsRefund(): bool {
    $enabled = \Civi::settings()->get('cmcic_enable_refunds');
    return $enabled === TRUE || $enabled === 1 || $enabled === '1';
  }

  /**
   * Process a refund request via Monetico recredit_paiement API.
   *
   * @param array $params Contains trxn_id, amount, currency, contribution_id
   * @return array Standard CiviCRM / MJWShared refund result array
   * @throws CRM_Core_Exception
   */
  public function doRefund(&$params): array {
    if (!$this->supportsRefund()) {
      throw new CRM_Core_Exception(ts(
        'Monetico online refunds are disabled. Enable setting cmcic_enable_refunds to proceed.'
      ));
    }

    $contributionID = (int) ($params['contribution_id'] ?? $params['id'] ?? 0);
    $currency = (string) ($params['currency'] ?? '');

    if (!$contributionID && !empty($params['trxn_id'])) {
      $payments = \Civi\Api4\Payment::get(FALSE)
        ->addSelect('contribution_id')
        ->addWhere('trxn_id', '=', $params['trxn_id'])
        ->addWhere('payment_processor_id', '=', $this->_paymentProcessor['id'])
        ->execute();
      if (count($payments) > 1) {
        throw new CRM_Core_Exception(sprintf(
        "Multiple Monetico payments found matching trxn_id '%s'. "
        . 'Refund requires an explicit contribution_id.',
        $params['trxn_id']
        ));
      }
      if (count($payments) === 1) {
        $contributionID = (int) $payments->first()['contribution_id'];
      }
    }

    if (!$contributionID) {
      throw new CRM_Core_Exception(ts('Could not determine contribution reference for refund.'));
    }

    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('total_amount', 'currency', 'receive_date', 'is_test', 'contribution_status_id:name')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->single();

    if (($contribution['contribution_status_id:name'] ?? '') === 'Refunded') {
      throw new CRM_Core_Exception("Contribution #{$contributionID} is already marked as Refunded in CiviCRM.");
    }

    $isTest = !empty($contribution['is_test']);
    $processor = $this->getOperationalProcessor($isTest ? 'test' : 'live');

    $totalAmount = (float) ($contribution['total_amount'] ?? 0);
    $orderCurrency = strtoupper((string) ($contribution['currency'] ?? 'EUR'));

    if (!empty($currency) && strtoupper($currency) !== $orderCurrency) {
      throw new CRM_Core_Exception(sprintf(
        'Refund currency mismatch: requested %s, but contribution currency is %s.',
        strtoupper($currency),
        $orderCurrency
      ));
    }

    $requestedRefundAmount = (float) CRM_Utils_Rule::cleanMoney($params['amount'] ?? $totalAmount);
    if ($requestedRefundAmount <= 0) {
      throw new CRM_Core_Exception(ts('Refund amount must be greater than zero.'));
    }

    // V1 Full Refund Rule: Must equal full contribution amount
    if (abs($requestedRefundAmount - $totalAmount) >= 0.01) {
      throw new CRM_Core_Exception(sprintf(
        'Monetico V1 online refund supports full refunds only. Requested: %.2f %s, Total: %.2f %s.',
        $requestedRefundAmount,
        $orderCurrency,
        $totalAmount,
        $orderCurrency
      ));
    }

    $receiveDate = strtotime((string) ($contribution['receive_date'] ?? 'now'));
    $statusEndpoint = CRM_Core_Payment_CmcicPaymentStatus::getEndpoint($processor->_mode === 'test');

    // Query Monetico EtatPaiement to verify bank-side status and already recredited amounts
    $statusResult = CRM_Core_Payment_CmcicPaymentStatus::query(
        [
          'version' => '2.0',
          'TPE' => (string) $processor->_paymentProcessor['user_name'],
          'date' => date('d/m/Y', $receiveDate ?: time()),
          'montant' => number_format($totalAmount, 2, '.', '') . $orderCurrency,
          'reference' => (string) $contributionID,
          'societe' => (string) $processor->_paymentProcessor['signature'],
        ],
        $processor->getKey(),
        $processor->getAlgorithm(),
        $statusEndpoint
    );

    $bankState = (string) ($statusResult['state'] ?? '');
    $capturedAmount = (float) ($statusResult['captured_amount'] ?? 0.0);

    if ($bankState !== 'PA' || $capturedAmount <= 0.0) {
      throw new CRM_Core_Exception(sprintf(
        'Monetico refund rejected: contribution #%d is not in a captured paid state on Monetico '
        . '(state: %s, captured: %.2f %s).',
        $contributionID,
        $bankState ?: 'unknown',
        $capturedAmount,
        $orderCurrency
      ));
    }

    $alreadyRecredited = (float) ($statusResult['recredits_total'] ?? 0.0);
    $soldeRemboursable = max(0.0, $capturedAmount - $alreadyRecredited);

    if ($requestedRefundAmount > ($soldeRemboursable + 0.001)) {
      throw new CRM_Core_Exception(sprintf(
        'Monetico refund rejected: requested amount (%.2f %s) exceeds available Monetico '
        . 'refundable balance (%.2f %s).',
        $requestedRefundAmount,
        $orderCurrency,
        $soldeRemboursable,
        $orderCurrency
      ));
    }

    if ($soldeRemboursable < 0.01 || $alreadyRecredited >= $totalAmount) {
      throw new CRM_Core_Exception(sprintf(
        'Monetico refund rejected: contribution #%d has already been refunded on Monetico portal '
        . '(already recredited: %.2f %s).',
        $contributionID,
        $alreadyRecredited,
        $orderCurrency
      ));
    }

    $capture = $processor->findRefundCapture(
        $statusResult['captures'] ?? [],
        $requestedRefundAmount,
        $soldeRemboursable
    );

    $refundResult = $processor->callMoneticoRecreditApi(
        $contributionID,
        $requestedRefundAmount,
        $orderCurrency,
        $soldeRemboursable,
        date('d/m/Y', $receiveDate ?: time()),
        $capture
    );

    \Civi::log()->info(sprintf(
        'Monetico recredit successful for contribution #%d: %.2f %s (refund_trxn_id: %s)',
        $contributionID,
        $requestedRefundAmount,
        $orderCurrency,
        $refundResult['refund_trxn_id']
    ));

    return [
      'refund_trxn_id' => $refundResult['refund_trxn_id'],
      'refund_status' => 'Completed',
      'fee_amount' => 0,
    ];
  }

  /**
   * Send a signed HTTP POST request to Monetico recredit_paiement.cgi API.
   *
   * @param int $contributionID
   * @param float $refundAmount
   * @param string $currency
   * @param float $soldeRemboursable
   * @param string $dateCommande
   * @param array|null $capture A single successful Monetico capture, when
   *   EtatPaiement provides the authorization and settlement date.
   * @return array
   * @throws CRM_Core_Exception
   */
  protected function callMoneticoRecreditApi(
        int $contributionID,
        float $refundAmount,
        string $currency,
        float $soldeRemboursable,
        string $dateCommande,
        $capture = NULL
    ): array {
    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('total_amount')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->single();

    $totalAmount = (float) ($contribution['total_amount'] ?? $refundAmount);
    $formattedTotal = number_format($totalAmount, 2, '.', '');
    $formattedRefund = number_format($refundAmount, 2, '.', '');
    $formattedPossible = number_format($soldeRemboursable, 2, '.', '');

    $fields = [
      'version' => '3.0',
      'TPE' => (string) $this->_paymentProcessor['user_name'],
      'date' => date('d/m/Y:H:i:s'),
      'date_commande' => $dateCommande,
      'montant' => $formattedTotal . $currency,
      'montant_recredit' => $formattedRefund . $currency,
      'montant_possible' => $formattedPossible . $currency,
      'reference' => (string) $contributionID,
      'lgue' => 'FR',
      'societe' => (string) $this->_paymentProcessor['signature'],
    ];

    if ($capture !== NULL) {
      $fields['date_remise'] = $this->formatSettlementDate((string) $capture['date_remise']);
      $fields['num_autorisation'] = $capture['authorization_number'];
    }

    $fields['MAC'] = CRM_Core_Payment_CmcicHmac::calculate(
        $fields,
        $this->getKey(),
        $this->getAlgorithm()
    );

    $baseUrl = $this->_mode === 'test'
        ? 'https://payment-api.e-i.com/test/recredit_paiement.cgi'
        : 'https://payment-api.e-i.com/recredit_paiement.cgi';

    $httpClient = new \GuzzleHttp\Client([
      'connect_timeout' => 5,
      'timeout' => 15,
      'verify' => TRUE,
    ]);

    try {
      $response = $httpClient->post($baseUrl, [
        'form_params' => $fields,
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded',
          'Accept' => 'text/plain',
        ],
        'http_errors' => FALSE,
      ]);
      $body = (string) $response->getBody();
    }
    catch (\Throwable $e) {
      throw new CRM_Core_Exception('Monetico recredit HTTP request failed: ' . $e->getMessage());
    }

    $parsed = [];
    $lines = explode("\n", str_replace("\r", "", $body));
    foreach ($lines as $line) {
      if (str_contains($line, '=')) {
        [$k, $v] = explode('=', trim($line), 2);
        $parsed[trim($k)] = trim($v);
      }
    }

    $cdr = (string) ($parsed['cdr'] ?? '-1');
    if ($cdr !== '0') {
      $errorDescriptions = [
        '-46' => 'La commande est déjà entièrement recréditée.',
        '-48' => 'Échec du recrédit (recrédit partiel non permis ou rejeté par la banque).',
        '-51' => 'Le recrédit global n\'est pas permis pour cette commande. '
        . 'Les données de recouvrement (date_remise et num_autorisation) sont nécessaires.',
        '-52' => 'Le montant déjà recrédité est incorrect.',
      ];
      $errorMsg = $errorDescriptions[$cdr] ?? ("Code d'erreur Monetico: cdr=" . $cdr);
      throw new CRM_Core_Exception('Échec du remboursement Monetico: ' . $errorMsg);
    }

    return [
      'cdr' => '0',
      'refund_trxn_id' => 'recredit-' . $contributionID . '-' . time(),
      'response' => $parsed,
    ];
  }

  /**
   * Select a capture-specific refund only when EtatPaiement identifies one
   * complete capture that covers the refundable balance. Otherwise retain the
   * documented global card-refund path.
   *
   * @param array $captures Monetico captures.
   * @param float $refundAmount Requested refund amount.
   * @param float $refundableBalance Remaining refundable balance.
   *
   * @return array|null
   */
  protected function findRefundCapture($captures, $refundAmount, $refundableBalance) {
    if (!is_array($captures) || count($captures) !== 1) {
      return NULL;
    }

    $capture = reset($captures);
    $captureAmount = (float) ($capture['amount'] ?? 0);
    $captureRecredits = (float) ($capture['recredits_total'] ?? 0);
    $captureBalance = max(0.0, $captureAmount - $captureRecredits);
    $dateRemise = (string) ($capture['date_remise'] ?? '');
    $authorizationNumber = (string) ($capture['authorization_number'] ?? '');

    if (
        $dateRemise === ''
        || $authorizationNumber === ''
        || abs($captureBalance - (float) $refundableBalance) >= 0.01
        || (float) $refundAmount > ($captureBalance + 0.001)
    ) {
      return NULL;
    }

    return $capture;
  }

  /**
   * EtatPaiement returns a capture settlement date as YYYY-MM-DD, while the
   * recredit API requires DD/MM/YYYY.
   */
  protected function formatSettlementDate($dateRemise) {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRemise);
    if ($date && $date->format('Y-m-d') === $dateRemise) {
      return $date->format('d/m/Y');
    }

    $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $dateRemise);
    if ($date && $date->format('d/m/Y') === $dateRemise) {
      return $dateRemise;
    }

    throw new CRM_Core_Exception('Monetico EtatPaiement returned an invalid capture settlement date.');
  }

  /**
   * Resolve the operational payment processor instance (live or test sibling).
   *
   * @param string $mode 'test' or 'live'
   * @return CRM_Core_Payment_Cmcic
   */
  public function getOperationalProcessor(string $mode = 'live'): CRM_Core_Payment_Cmcic {
    $current = $this->getPaymentProcessor();
    $requestedIsTest = ($mode === 'test');
    $currentIsTest = !empty($current['is_test']) || ($this->_mode === 'test');

    if ($currentIsTest === $requestedIsTest) {
      return $this;
    }

    $instance = \Civi\Payment\System::singleton()->getByName(
        (string) ($current['name'] ?? ''),
        $requestedIsTest
    );

    if (
        !($instance instanceof CRM_Core_Payment_Cmcic)
        || ((bool) !empty($instance->getPaymentProcessor()['is_test'])) !== $requestedIsTest
    ) {
      throw new CRM_Core_Exception(sprintf(
        'Could not resolve operational %s Monetico payment processor for processor #%d.',
        $mode,
        (int) ($current['id'] ?? 0)
      ));
    }

    return $instance;
  }

}
