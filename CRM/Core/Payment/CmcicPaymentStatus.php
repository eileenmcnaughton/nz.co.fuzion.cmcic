<?php

/**
 * Client for Monetico's signed Payment Status service.
 */
class CRM_Core_Payment_CmcicPaymentStatus {

  const TEST_ENDPOINT = 'https://payment-api.e-i.com/test/etatpaiement.cgi';
  const LIVE_ENDPOINT = 'https://payment-api.e-i.com/etatpaiement.cgi';

  /**
   * @param bool $testMode
   *
   * @return string
   */
  public static function getEndpoint($testMode) {
    return $testMode ? self::TEST_ENDPOINT : self::LIVE_ENDPOINT;
  }

  /**
   * Map Monetico's payment state to the CiviCRM Checkout state.
   *
   * @param string $state
   *
   * @return string
   */
  public static function getCheckoutStatus($state) {
    if ($state === 'PA') {
      return 'success';
    }
    if ($state === 'AN') {
      return 'cancel';
    }
    if (in_array($state, array('RE', 'GR', 'AP'), TRUE)) {
      return 'fail';
    }
    return 'pending';
  }

  /**
   * Retrieve a payment status from Monetico.
   *
   * @param array $fields
   * @param string $key
   * @param string $algorithm
   * @param string $endpoint
   * @param callable|null $httpClient
   *
   * @return array
   */
  public static function query($fields, $key, $algorithm, $endpoint, $httpClient = NULL) {
    $required = array('version', 'TPE', 'date', 'montant', 'reference', 'societe');
    foreach ($required as $field) {
      if (empty($fields[$field])) {
        throw new CRM_Core_Exception('Missing required Monetico payment status field: ' . $field);
      }
    }

    $fields['MAC'] = CRM_Core_Payment_CmcicHmac::calculate($fields, $key, $algorithm);
    if ($httpClient) {
      $body = $httpClient($endpoint, $fields);
    }
    else {
      $client = new \GuzzleHttp\Client(array(
        'connect_timeout' => 2,
        'timeout' => 5,
        'verify' => TRUE,
      ));
      $response = $client->post($endpoint, array(
        'form_params' => $fields,
        'headers' => array('Accept' => 'application/xml'),
        'http_errors' => FALSE,
      ));
      if ($response->getStatusCode() !== 200) {
        throw new CRM_Core_Exception('Monetico payment status request failed with HTTP ' . $response->getStatusCode() . '.');
      }
      $body = (string) $response->getBody();
    }

    $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
    if (!$xml) {
      throw new CRM_Core_Exception('Monetico payment status response is not valid XML.');
    }
    if (isset($xml->cdr) && (string) $xml->cdr !== '') {
      throw new CRM_Core_Exception('Monetico payment status error: ' . (string) $xml->cdr . '.');
    }
    if (empty($xml->etat)) {
      throw new CRM_Core_Exception('Monetico payment status response does not contain a state.');
    }

    $recreditsTotal = 0.0;
    if (isset($xml->recredits->total)) {
      $recreditsTotal = (float) preg_replace('/[^0-9\.]/', '', (string) $xml->recredits->total);
    }
    $capturedAmount = 0.0;
    if (isset($xml->montantrecouvre)) {
      $capturedAmount = (float) preg_replace('/[^0-9\.]/', '', (string) $xml->montantrecouvre);
    }

    return array(
      'state' => (string) $xml->etat,
      'authorization_number' => (string) ($xml->numauto ?? ''),
      'recredits_total' => $recreditsTotal,
      'captured_amount' => $capturedAmount,
      'raw_xml' => $xml,
    );
  }

}
