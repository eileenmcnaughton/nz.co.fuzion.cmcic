<?php

class CRM_Core_Payment_Cmcic extends CRM_Core_Payment{
  CONST CHARSET = 'iso-8859-1';

  protected $_mode = NULL;

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

    $url = CRM_Utils_System::url("civicrm/payment/ipn", "processor_id=1", TRUE, null, false);
    if ($component == 'event') {
      $cancelURL = CRM_Utils_System::url('civicrm/event/register',
        "_qf_Confirm_display=true&qfKey={$params['qfKey']}",
        TRUE, NULL, FALSE
      );
    }
    elseif ($component == 'contribute') {
      $cancelURL = CRM_Utils_System::url('civicrm/contribute/transact',
        "_qf_Confirm_display=true&qfKey={$params['qfKey']}",
        TRUE, NULL, FALSE
      );
    }
    $returnURL = CRM_Utils_System::url($url,
      "_qf_ThankYou_display=1&qfKey={$params['qfKey']}",
      TRUE, NULL, FALSE
    );

    /**
    * Build the private data string to pass to DPS, which they will give back to us with the
    *
    * transaction result.  We are building this as a comma-separated list so as to avoid long URLs.
    *
    * Parameters passed: a=contactID, b=contributionID,c=contributionTypeID,d=invoiceID,e=membershipID,f=participantID,g=eventID
    */

    $privateData = "a={$params['contactID']},b={$params['contributionID']},c={$params['contributionTypeID']},d={$params['invoiceID']}";

    if ($component == 'event') {
      $merchantRef = substr($params['contactID'] . "-" . substr($params['description'], 27, 20), 0, 24);
      $privateData .= ",f={$params['participantID']},g={$params['eventID']}";
    }
    elseif ($component == 'contribute') {
      $membershipID = CRM_Utils_Array::value('membershipID', $params);
      if ($membershipID) {
        $privateData .= ",e=$membershipID";
      }
      $merchantRef = substr($params['contactID'] . "-" . $params['contributionID'] . " " . substr($params['description'], 20, 20), 0, 24);

    }

    $paymentParams = array(
      'url_retour' => $url,
      'submit_to' => $this->_paymentProcessor['url_site'],
      'url_retour_ok' => $url,
      'url_retour_err' => $cancelURL,
      'sealed_params' => array(
        'TPE' => $this->_paymentProcessor['user_name'],
        'date' => date("d/m/Y:H:i:s"),
        'montant' => str_replace(",", "", number_format($params['amount'], 2)) . $params['currencyID'],
        'reference' => $params['contributionID'], //$merchantRef,
        'texte-libre' => 'def', //$privateData . $params['qfKey'] . $component . ",".$this->_paymentProcessor['id'],
        'version' => '3.0',
        //@todo - get language code
        'lgue' => 'EN',
        'societe' => $this->_paymentProcessor['signature'],
        'mail' => empty($params['email']) ? $params['email-Primary'] : $params['email'],
      ),
    );

    // Allow further manipulation of params via custom hooks
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $paymentParams);
    $algorithm = empty($this->_paymentProcessor['subject']) ? 'md5' : $this->_paymentProcessor['subject'];
    $paymentParams['MAC'] = $this->encodeMac($this->getUsableKey($this->_paymentProcessor['password']), $paymentParams['sealed_params'], $algorithm);
    $paymentParams = array_merge($paymentParams, $paymentParams['sealed_params']);
    unset($paymentParams['sealed_params']);
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/cmcic', $paymentParams));

    /*
     *  determine whether method is pxaccess or pxpay by whether signature (mac key) is defined
    */
  }

  /**
   * calculate MAC key
   * @param unknown $key
   * @param unknown $params
   * @param unknown $algorithm
   * @return string
   */
  private function encodeMac($key, $params, $algorithm) {
    $string = implode('*', $params) . '**********';
    return hash_hmac($algorithm, $string, $key);
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

  function handlePaymentNotification(){
echo "<pre>";
print_r($_REQUEST);
die;
    $payFlowLinkIPN = new CRM_Core_Payment_PayflowLinkIPN( );
    $payFlowLinkIPN ->main( );
    //if for any reason we come back here
    CRM_Core_Error::debug_log_message( "It should not be possible to reach this line" );
  }

}