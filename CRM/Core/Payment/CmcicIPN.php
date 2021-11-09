<?php

class CRM_Core_Payment_CmcicIPN extends CRM_Core_Payment_BaseIPN{

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
    parent::__construct();
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

    $ordered_fields = array();
    $list           = array(
      'TPE',
      'date',
      'montant',
      'reference',
      'texte-libre',
      'version',
      'code-retour',
      'cvx',
      'vld',
      'brand',
      'status3ds',
      'numauto',
      'motifrefus',
      'originecb',
      'bincb',
      'hpancb',
      'ipclient',
      'originetr',
      'veres',
      'pares',
    );

    foreach ($list as $name) {
      $ordered_fields[$name] = isset($fields[$name]) ? $fields[$name] : '';
    }

    $ordered_fields['version'] = '3.0';
    $ordered_fields[] = '';

    $mac = hash_hmac($this->_paymentProcessor->getAlgorithm(), implode('*', $ordered_fields), $this->_paymentProcessor->getKey());

    return (strtolower($mac) == strtolower($fields['MAC']));
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
    $resultCode = $this->retrieve('code-retour', 'String');
    $contributionID = $this->retrieve('reference', 'Integer');
    $trxn_id = $contributionID . '-' . $this->retrieve('numauto', 'String', FALSE);

    if(in_array($resultCode, $successfulResults)) {
      if($resultCode == 'payetest') {
        $trxn_id = 'test' . $contributionID . uniqid();
      }
      civicrm_api3('contribution', 'completetransaction', array(
        'id' => $contributionID,
        'trxn_id' => $trxn_id,
      ));
      $this->cmcic_receipt_exit(TRUE);
    }
    elseif($resultCode == 'Annulation') {
      $this->processFailedTransaction($contributionID);
      $this->cmcic_receipt_exit(TRUE);
    }
    return TRUE;
  }

  /**
   * Process failed transaction - would be nice to do this through api too but for now lets put in
   * here - this is a copy & paste of the completetransaction api
   * @param unknown $contributionID
   */
  function processFailedTransaction($contributionID) {
    $input = $ids = array();
    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->id = $contributionID;
    $contribution->find(TRUE);
    if(!$contribution->id == $contributionID){
      throw new Exception('A valid contribution ID is required', 'invalid_data');
    }
    try {
      if(!$contribution->loadRelatedObjects($input, $ids, FALSE, TRUE)){
        throw new Exception('failed to load related objects');
      }
      $objects = $contribution->_relatedObjects;
      $objects['contribution'] = &$contribution;
      $input['component'] = $contribution->_component;
      $input['is_test'] = $contribution->is_test;
      $input['amount'] = $contribution->total_amount;
      // @todo required for base ipn but problematic as api layer handles this
      $transaction = new CRM_Core_Transaction();
      $ipn = new CRM_Core_Payment_BaseIPN();
      $ipn->failed($objects, $transaction, $input);
    }
    catch (Exception $e) {
    }
  }
}
