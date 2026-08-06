<?php

class CRM_Core_Payment_CmcicIPN {

  static $_paymentProcessor = NULL;

  /**
   * Input parameters from payment processor. Store these so that
   * the code does not need to keep retrieving from the http request
   * @var array
   */
  protected $_inputParameters = array();

  /**
   * store for the variables from the invoice string
   * @var array
  */
  protected $_invoiceData = array();

  protected $_exitMode = FALSE;
  /**
   * Are we dealing with an event an 'anything else' (contribute)
   * @var string component
   */
  protected $_component = 'contribute';
  /**
   * constructor function
   */
  function __construct($inputData) {
    $this->setInputParameters($inputData);
    $this->_exitMode = !empty($inputData['exit_mode']);
  }

  /**
   * Store payment processor input for later validation and processing.
   *
   * @param array $inputParameters
   */
  function setInputParameters($inputParameters) {
    $this->_inputParameters = $inputParameters;
  }

  /**
   * output required response for CMCIC process
   * @param boolean $mac_ok
   * @return string
   */
  function cmcic_receipt($mac_ok) {
    return "version=2\ncdr=" . ($mac_ok ? '0' : '1') . "\n";
  }

  /**
   * output result and exit - for when url is being hit by cmcic
   * @param unknown $mac_ok
   */
  function cmcic_receipt_exit($mac_ok) {
    echo $this->cmcic_receipt($mac_ok);
    if($this->_exitMode) {
      exit;
    }
  }

  /**
   * check response from cmcic using private key
   * @param string $key
   * @param array $fields
   * @param string $algorithm
   * @return boolean
   */
  function cmcic_validate_response() {
    $fields = $this->_inputParameters;
    if (!isset($fields['MAC']) || empty($fields['MAC'])) {
      return FALSE;
    }

    unset($fields['MAC'], $fields['exit_mode']);
    foreach ($fields as $name => $value) {
      $fields[$name] = str_replace(' ', '+', $value);
    }

    $mac = CRM_Core_Payment_CmcicHmac::calculate(
      $fields,
      $this->_paymentProcessor->getKey(),
      $this->_paymentProcessor->getAlgorithm()
    );
    if (hash_equals(strtolower($mac), strtolower($this->_inputParameters['MAC']))) {
      return TRUE;
    }

    $legacyFields = array();
    $legacyNames = array(
      'TPE', 'date', 'montant', 'reference', 'texte-libre', 'version',
      'code-retour', 'cvx', 'vld', 'brand', 'status3ds', 'numauto',
      'motifrefus', 'originecb', 'bincb', 'hpancb', 'ipclient', 'originetr',
      'veres', 'pares',
    );
    foreach ($legacyNames as $name) {
      $legacyFields[$name] = isset($this->_inputParameters[$name]) ? $this->_inputParameters[$name] : '';
    }
    $legacyFields['version'] = '3.0';
    $legacyFields[] = '';
    $legacyMac = hash_hmac(
      $this->_paymentProcessor->getAlgorithm(),
      implode('*', $legacyFields),
      $this->_paymentProcessor->getKey()
    );

    return hash_equals(strtolower($legacyMac), strtolower($this->_inputParameters['MAC']));
  }

  /**
   * @param string $name of variable to return
   * @param string $type data type
   *   - String
   *   - Integer
   * @param string $location - deprecated
   * @param boolean $abort abort if empty
   * @return Ambigous <mixed, NULL, value, unknown, array, number>
   */
  function retrieve($name, $type, $abort = TRUE) {
    $value = CRM_Utils_Type::validate(
      CRM_Utils_Array::value($name, $this->_inputParameters),
      $type,
      FALSE
    );
    if ($abort && $value === NULL) {
      throw new CRM_Core_Exception("Could not find an entry for $name");
    }
    return $value;
  }

  /**
   * This is the main function to call. It should be sufficient to instantiate the class
   * (with the input parameters) & call this & all will be done
   *
   * @todo the references to POST throughout this class need to be removed
   * @return void|boolean|Ambigous <void, boolean>
   */
  function main($paymentProcessor) {
    //we say contribute here as a dummy param as we are using the api to complete & we don't need to know
    $this->_paymentProcessor = new CRM_Core_Payment_Cmcic('contribute', $paymentProcessor);
    if(!$this->cmcic_validate_response()) {
      $this->cmcic_receipt_exit(FALSE);
      return;
    }

    //since we have done MAC validation we can assume it is all good & just use the api to complete
    // based on the contribution id
    $successfulResults = array('payetest', 'paiement');
    $resultCode = (string) $this->retrieve('code-retour', 'String');
    $contributionID = (int) $this->retrieve('reference', 'Integer');
    $numauto = (string) $this->retrieve('numauto', 'String', FALSE);
    $trxn_id = $contributionID . '-' . $numauto;

    // Fetch existing contribution status, trxn_id and financial details to guarantee idempotency, status safety and amount validation
    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name', 'total_amount', 'currency', 'trxn_id')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first();

    if (!$contribution) {
      \Civi::log()->error("Monetico IPN received for non-existent contribution #{$contributionID}");
      $this->cmcic_receipt_exit(FALSE);
      return FALSE;
    }

    $currentStatus = $contribution['contribution_status_id:name'] ?? '';
    $existingTrxnId = (string) ($contribution['trxn_id'] ?? '');

    // Idempotency: If the contribution is already Completed, verify that it is the exact same transaction replayed
    if ($currentStatus === 'Completed') {
      $isSameTransaction = ($resultCode === 'payetest')
        || ($existingTrxnId !== '' && $existingTrxnId === $trxn_id);

      if ($isSameTransaction) {
        \Civi::log()->debug("Monetico IPN received for already completed contribution #{$contributionID} (same transaction). Returning idempotent receipt.");
        $this->cmcic_receipt_exit(TRUE);
        return TRUE;
      }

      \Civi::log()->error("Monetico IPN received second distinct transaction for already completed contribution #{$contributionID}. Rejecting.");
      $this->cmcic_receipt_exit(FALSE);
      return FALSE;
    }

    // Strict Validation of IPN amount & currency format against the CiviCRM contribution
    $montantBrut = (string) $this->retrieve('montant', 'String');
    if (!preg_match('/^([0-9]+(?:\.[0-9]{1,2})?)([A-Z]{3})$/', $montantBrut, $matches)) {
      \Civi::log()->error("Monetico IPN malformed montant format for contribution #{$contributionID}: '{$montantBrut}'");
      $this->cmcic_receipt_exit(FALSE);
      return FALSE;
    }

    $rawAmountString = $matches[1];
    $receivedCurrency = strtoupper($matches[2]);

    // Reject fractional amounts for zero-decimal currencies (e.g. 10.25JPY is invalid)
    if ($this->isZeroDecimalCurrency($receivedCurrency) && str_contains($rawAmountString, '.')) {
      \Civi::log()->error("Monetico IPN invalid decimal fraction for zero-decimal currency {$receivedCurrency} on contribution #{$contributionID}: '{$montantBrut}'");
      $this->cmcic_receipt_exit(FALSE);
      return FALSE;
    }

    $receivedAmountRaw = (float) $rawAmountString;
    $expectedAmountRaw = (float) ($contribution['total_amount'] ?? 0);
    $expectedCurrency = strtoupper((string) ($contribution['currency'] ?? ''));

    $receivedUnits = $this->amountToMinorUnits($receivedAmountRaw, $receivedCurrency);
    $expectedUnits = $this->amountToMinorUnits($expectedAmountRaw, $expectedCurrency);

    if ($receivedUnits !== $expectedUnits || $receivedCurrency !== $expectedCurrency) {
      \Civi::log()->error(sprintf(
        'Monetico IPN amount/currency mismatch for contribution #%d: received %d units (%s), expected %d units (%s).',
        $contributionID,
        $receivedUnits,
        $receivedCurrency,
        $expectedUnits,
        $expectedCurrency
      ));
      $this->cmcic_receipt_exit(FALSE);
      return FALSE;
    }

    if (in_array($resultCode, $successfulResults, TRUE)) {
      if ($resultCode === 'payetest') {
        $trxn_id = 'test' . $contributionID . uniqid();
      }
      civicrm_api3('contribution', 'completetransaction', array(
        'id' => $contributionID,
        'trxn_id' => $trxn_id,
        'payment_processor_id' => $paymentProcessor['id'],
      ));
      $this->cmcic_receipt_exit(TRUE);
    }
    elseif ($resultCode === 'Annulation') {
      $this->processFailedTransaction($contributionID, $currentStatus);
      $this->cmcic_receipt_exit(TRUE);
    }
    return TRUE;
  }

  /**
   * Process failed transaction.
   *
   * @param int $contributionID
   * @param string $currentStatus
   */
  function processFailedTransaction($contributionID, $currentStatus = '') {
    if ($currentStatus === 'Completed') {
      \Civi::log()->warning("Monetico IPN received cancellation for already completed contribution #{$contributionID}. Ignoring.");
      return;
    }
    \Civi\Api4\Contribution::update(FALSE)->setValues([
      'cancel_date' => 'now',
      'contribution_status_id:name' => 'Failed',
    ])->addWhere('id', '=', $contributionID)->execute();
    \Civi::log()->debug("Setting contribution status to Failed for contribution #" . $contributionID);
  }

  /**
   * Check if ISO currency code is a zero-decimal currency (JPY, KRW, VND, etc.).
   */
  protected function isZeroDecimalCurrency(string $currency): bool {
    $zeroDecimalCurrencies = array('JPY', 'KRW', 'CLP', 'PYG', 'UGX', 'VND', 'BIF', 'DJF', 'GNF', 'KMF', 'MGA', 'RWF', 'VUV', 'XAF', 'XOF', 'XPF');
    return in_array(strtoupper($currency), $zeroDecimalCurrencies, TRUE);
  }

  /**
   * Convert float amount into integer minor units, accounting for zero-decimal currencies.
   */
  protected function amountToMinorUnits(float $amount, string $currency): int {
    if ($this->isZeroDecimalCurrency($currency)) {
      return (int) round($amount);
    }
    return (int) round($amount * 100);
  }
}
