<?php

class CRM_Core_Payment_Cmcic extends CRM_Core_Payment{
  CONST CHARSET = 'iso-8859-1';

  protected $_mode = NULL;

  protected $_key = '';

  protected $_algorithm = 'md5';
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
    $this->_algorithm = empty($paymentProcessor['subject']) ? 'md5' : $paymentProcessor['subject'];

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
  static function &singleton($mode = 'test', &$paymentProcessor, &$paymentForm = NULL, $force = FALSE) {
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
  function doTransferCheckout(&$params, $component) {
    $component = strtolower($component);
    if ($component == 'event') {
      $baseURL = 'civicrm/event/register';
      $cancelURL = urlencode(CRM_Utils_System::url($baseURL, array(
        'reset' => 1,
        'cc' => 'fail',
        'participantId' => $orderID[4],
      ),
      TRUE, NULL, FALSE
      ));
    }
    elseif ($component == 'contribute') {
      $baseURL = 'civicrm/contribute/transact';
      $cancelURL = urlencode(CRM_Utils_System::url($baseURL, array(
        '_qf_Main_display' => 1,
        'qfKey' => $params['qfKey'],
        'cancel' => 1,
        ),
        TRUE, NULL, FALSE
      ));
    }

    $returnOKURL = urlencode(CRM_Utils_System::url($baseURL,array(
      '_qf_ThankYou_display' => 1,
       'qfKey' => $params['qfKey']
      ),
      TRUE, NULL, FALSE
    ));
    $returnUrl = urlencode(CRM_Utils_System::url($baseURL,array(
      '_qf_Confirm_display' => 'true',
       'qfKey' => $params['qfKey']
      ),
      TRUE, NULL, FALSE
    ));

    if ($component == 'event') {
      $merchantRef = $params['contactID'] . "-" . $params['description'];//, 27, 20), 0, 24);
    }
    elseif ($component == 'contribute') {
      $merchantRef = $params['contactID'] . "-" . $params['contributionID'];// . " " . substr($params['description'], 20, 20), 0, 24);
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
      'url_retour' => $returnURL,
      'submit_to' => $this->_paymentProcessor['url_site'],
      'url_retour_ok' => $returnOKURL,
      'url_retour_err' => $cancelURL,
      'sealed_params' => array(
        'TPE' => $this->_paymentProcessor['user_name'],
        'date' => date("d/m/Y:H:i:s"),
        'montant' => str_replace(",", "", number_format($params['amount'], 2)) . $params['currencyID'],
        'reference' => $params['contributionID'], //$merchantRef,
        'texte-libre' => $this->urlEncodeField($merchantRef, 24), //$privateData . $params['qfKey'] . $component . ",".$this->_paymentProcessor['id'],
        'version' => '3.0',
        //@todo - get language code
        'lgue' => $lang,
        'societe' => $this->_paymentProcessor['signature'],
        'mail' => $email,
      ),
    );

    // Allow further manipulation of params via custom hooks
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $paymentParams);
    $paymentParams['MAC'] = $this->encodeMac($paymentParams['sealed_params']);
    $paymentParams = array_merge($paymentParams, $paymentParams['sealed_params']);
    unset($paymentParams['sealed_params']);
    $query_string = '';
    foreach ($paymentParams as $name => $value) {
      $query_string .= $name . '=' . $value . '&';
    }

    // Remove extra &
    $query_string = rtrim($query_string, '&');

    // Redirect the user to the payment url.
    CRM_Utils_System::redirect($this->_paymentProcessor['url_site'] . '?' . $query_string);
    // looks like we dodged the bullet on POST being required. may as well keep this & the page
    // in case they tighten up later
    // CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/cmcic', $paymentParams));
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
    return htmlentities(substr($value, $length));

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
   * calculate MAC key
   * @param unknown $key
   * @param unknown $params
   * @param unknown $algorithm
   * @return string
   */
  private function encodeMac($params) {
    $string = implode('*', $params) . '**********';
    return hash_hmac($this->getAlgorithm(), $string, $this->getKey());
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

  function handlePaymentNotification(){
    $logTableExists = FALSE;
    $checkTable = "SHOW TABLES LIKE 'civicrm_notification_log'";
    $dao = CRM_Core_DAO::executeQuery($checkTable);
    if(!$dao->N) {
      CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS `civicrm_notification_log` (
      `id` INT(10) NOT NULL AUTO_INCREMENT,
      `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `message_type` VARCHAR(255) NULL DEFAULT NULL,
      `message_raw` LONGTEXT NULL,
       PRIMARY KEY (`id`)
      )");
    }

    $dao = CRM_Core_DAO::executeQuery("INSERT INTO civicrm_notification_log (message_raw, message_type) VALUES (%1, 'cmcic')",
      array(1 => array(json_encode($_REQUEST), 'String'))
    );
    $ipn = new CRM_Core_Payment_CmcicIPN(array_merge($_REQUEST, array('exit_mode' => TRUE)));
    $ipn->main();
    //if for any reason we come back here
    CRM_Core_Error::debug_log_message( "It should not be possible to reach this line" );
  }
}