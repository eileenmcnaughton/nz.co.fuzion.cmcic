<?php

class CRM_Core_Payment_Cmcic extends CRM_Core_Payment{
  CONST CHARSET = 'iso-8859-1';

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
  static private $_singleton = NULL;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {

    $this->_mode = $mode;
    $this->_key = $paymentProcessor['password'];
    $this->_algorithm = empty($paymentProcessor['subject']) ? 'sha1' : $paymentProcessor['subject'];

    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('CMCIC');
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode = 'test', &$paymentProcessor = NULL, &$paymentForm = NULL, $force = FALSE) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new CRM_Core_Payment_Cmcic($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  function checkConfig() {
    $config = CRM_Core_Config::singleton();

    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('POS terminal number is not set in the Administer &raquo; System Settings &raquo; Payment Processors');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('Merchant security key is not set in the Administer &raquo; System Settings &raquo; Payment Processors');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  function setessCheckOut(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * Main transaction function
   *
   * @param array $params  name value pair of contribution data
   *
   * @return void
   * @access public
   *
   */
  function doPayment(&$params, $component = 'contribute') {
    $component = strtolower($component);
    $contributionID = !empty($params['contributionID']) ? $params['contributionID'] : (!empty($params['contribution_id']) ? $params['contribution_id'] : NULL);
    if (!$contributionID) {
      Civi::log()->error('Cannot start Monetico checkout without a contribution ID.', array(
        'parameter_keys' => array_keys($params),
      ));
      throw new CRM_Core_Exception(ts('Unable to prepare the payment reference.'));
    }

    if ($component == 'event') {
      $baseURL = 'civicrm/event/register';
      $cancelURL = CRM_Utils_System::url($baseURL, array(
        'reset' => 1,
        'cc' => 'fail',
        'participantId' => $orderID[4],
      ),
      TRUE, NULL, FALSE
      );
    }
    elseif ($component == 'contribute') {
      $baseURL = 'civicrm/contribute/transact';
      $cancelURL = CRM_Utils_System::url($baseURL, array(
        '_qf_Main_display' => 1,
        'qfKey' => $params['qfKey'],
        'cancel' => 1,
        ),
        TRUE, NULL, FALSE
      );
    }

    $returnOKURL = CRM_Utils_System::url($baseURL,array(
      '_qf_ThankYou_display' => 1,
       'qfKey' => $params['qfKey']
      ),
      TRUE, NULL, FALSE
    );

    if ($component == 'event') {
      $merchantRef = $params['contactID'] . "-" . $params['eventID'];//, 27, 20), 0, 24);
    }
    elseif ($component == 'contribute') {
      $merchantRef = $params['contactID'] . "-" . $contributionID;// . " " . substr($params['description'], 20, 20), 0, 24);
    }
    CRM_Utils_System::redirect($this->prepareHostedCheckout($params, $returnOKURL, $cancelURL, $merchantRef));
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
  function prepareHostedCheckout($params, $returnOKURL, $cancelURL, $merchantRef) {
    $contributionID = !empty($params['contributionID']) ? $params['contributionID'] : (!empty($params['contribution_id']) ? $params['contribution_id'] : NULL);
    if (!$contributionID) {
      throw new CRM_Core_Exception(ts('Unable to prepare the payment reference.'));
    }

    $emailFields  = array('email', 'email-Primary', 'email-5');
    $email = '';
    foreach ($emailFields as $emailField) {
      if(!empty($params[$emailField])) {
        $email = $params[$emailField];
      }
    }
    $lang = $this->getLanguage();

    $paymentParams = array(
      'TPE' => $this->_paymentProcessor['user_name'],
      'contexte_commande' => CRM_Core_Payment_CmcicOrderContext::buildFromPaymentParams($params),
      'date' => date("d/m/Y:H:i:s"),
      'lgue' => $lang,
      'mail' => $email,
      'montant' => str_replace(",", "", number_format($params['amount'], 2)) . $params['currencyID'],
      'reference' => $contributionID,
      'societe' => $this->_paymentProcessor['signature'],
      'texte-libre' => $this->urlEncodeField($merchantRef, 24),
      'url_retour_ok' => $returnOKURL,
      'url_retour_err' => $cancelURL,
      'version' => '3.0',
    );

    // Allow further manipulation of params via custom hooks
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $paymentParams);
    $paymentParams['MAC'] = CRM_Core_Payment_CmcicHmac::calculate(
      $paymentParams,
      $this->getKey(),
      $this->getAlgorithm()
    );

    CRM_Core_Session::singleton()->set('checkout', array(
      'fields' => $paymentParams,
      'url' => $this->_paymentProcessor['url_site'],
    ), 'cmcic');
    return CRM_Utils_System::url('civicrm/cmcic', array('reset' => 1));
  }

  /**
   * Prepare a hosted checkout for a contribution created by CiviCRM Checkout.
   *
   * @param int $contributionID
   * @param string $landingURL
   *
   * @return string
   */
  function startHostedCheckoutForContribution($contributionID, $landingURL) {
    $contribution = civicrm_api3('Contribution', 'getsingle', array(
      'id' => $contributionID,
      'return' => array('contact_id', 'total_amount', 'currency'),
    ));

    $params = array(
      'contributionID' => $contributionID,
      'contactID' => $contribution['contact_id'],
      'amount' => $contribution['total_amount'],
      'currencyID' => $contribution['currency'],
    );
    $merchantRef = $params['contactID'] . '-' . $contributionID;

    return $this->prepareHostedCheckout(
      $params,
      $this->addHostedCheckoutReturnMarker($landingURL, 'ok'),
      $this->addHostedCheckoutReturnMarker($landingURL, 'err'),
      $merchantRef
    );
  }

  /**
   * Add a non-authoritative browser return marker to the checkout landing URL.
   *
   * @param string $landingURL
   * @param string $result
   *
   * @return string
   */
  function addHostedCheckoutReturnMarker($landingURL, $result) {
    return $landingURL
      . (strpos($landingURL, '?') === FALSE ? '?' : '&')
      . 'cmcic_return=' . rawurlencode($result);
  }

  /**
   * Query Monetico and reconcile a pending hosted checkout contribution.
   *
   * @param int $contributionID
   *
   * @return string CiviCRM Checkout status
   */
  function synchronizeHostedCheckoutContribution($contributionID) {
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
      array(
        'version' => '2.0',
        'TPE' => $this->_paymentProcessor['user_name'],
        'date' => date('d/m/Y', $orderDate),
        'montant' => str_replace(',', '', number_format($contribution['total_amount'], 2)) . $contribution['currency'],
        'reference' => (string) $contributionID,
        'societe' => $this->_paymentProcessor['signature'],
      ),
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
      civicrm_api3('contribution', 'completetransaction', array(
        'id' => $contributionID,
        'trxn_id' => $trxnId,
      ));
    }
    elseif ($checkoutStatus === 'cancel' || $checkoutStatus === 'fail') {
      \Civi\Api4\Contribution::update(FALSE)
        ->setValues(array(
          'cancel_date' => 'now',
          'contribution_status_id:name' => $checkoutStatus === 'cancel' ? 'Cancelled' : 'Failed',
        ))
        ->addWhere('id', '=', $contributionID)
        ->execute();
    }

    return $checkoutStatus;
  }

  /**
   * Cut field to correct length without truncating mid character
   * @param string $value
   * @param integer $fieldlength
   * @return string
   */
  function urlEncodeField($value, $fieldlength) {
    //@todo - we need to do more testing about the encoding - at this stage we have stopped
    // passing description strings until we can sort
    return substr($value, 0, $fieldlength);

    /**
    $string = substr(rawurlencode($value), 0, $fieldlength);
    $lastPercent = strrpos($string, '%');
    if ($lastPercent > $fieldlength - 3) {
      $string = substr($string, 0, $lastPercent);
    }
    return $string;
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
  function getLanguage() {
    global $tsLocale;
    $lang = substr($tsLocale, 0, 2);
    $validLangs = array('fr', 'en', 'de', 'it', 'es', 'nl', 'pt', 'sv');
    if(in_array($lang, $validLangs)) {
      return strtoupper($lang);
    }
    return 'FR';
  }

  /**
   * getter for key
   * @return string
   */
  function getKey() {
    return $this->getUsableKey($this->_key);
  }


  /**
   * getter for algorithm
   * @return string
   */
  function getAlgorithm() {
    return $this->_algorithm;
  }

  function handlePaymentNotification() {
    $ipn = new CRM_Core_Payment_CmcicIPN(array_merge($_REQUEST, array('exit_mode' => TRUE)));
    $ipn->main($this->_paymentProcessor);

    //if for any reason we come back here
    CRM_Core_Error::debug_log_message( "It should not be possible to reach this line" );
  }
}
