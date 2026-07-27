<?php

/**
 * Builds the minimal Monetico DSP2 order context.
 */
class CRM_Core_Payment_CmcicOrderContext {

  /**
   * Build the base64-encoded context from an allowlisted billing address.
   *
   * @param array $billing
   *
   * @return string
   * @throws InvalidArgumentException
   */
  public static function build($billing) {
    $required = array('addressLine1', 'city', 'postalCode', 'country');
    foreach ($required as $field) {
      if (empty($billing[$field])) {
        throw new InvalidArgumentException('Missing required Monetico billing field: ' . $field);
      }
    }

    $allowed = array(
      'civility', 'name', 'firstName', 'lastName', 'middleName', 'address',
      'addressLine1', 'addressLine2', 'addressLine3', 'city', 'postalCode',
      'country', 'stateOrProvince', 'countrySubdivision', 'email', 'phone',
      'mobilePhone', 'homePhone', 'workPhone',
    );
    $contextBilling = array();
    foreach ($allowed as $field) {
      if (isset($billing[$field]) && $billing[$field] !== '') {
        $contextBilling[$field] = $billing[$field];
      }
    }

    return base64_encode(json_encode(array('billing' => $contextBilling), JSON_UNESCAPED_UNICODE));
  }

  /**
   * Build the order context from CiviCRM payment parameters.
   *
   * @param array $params
   *
   * @return string
   */
  public static function buildFromPaymentParams($params) {
    $billing = array();
    $map = array(
      'firstName' => array('firstName', 'first_name'),
      'lastName' => array('lastName', 'last_name'),
      'addressLine1' => array('billingStreetAddress', 'street_address'),
      'addressLine2' => array('billingSupplementalAddress1'),
      'addressLine3' => array('billingSupplementalAddress2'),
      'city' => array('billingCity', 'city'),
      'postalCode' => array('billingPostalCode', 'postal_code'),
      'email' => array('email', 'email-Primary', 'email-5'),
      'phone' => array('phone'),
    );
    foreach ($map as $target => $sources) {
      foreach ($sources as $source) {
        if (isset($params[$source]) && $params[$source] !== '') {
          $billing[$target] = $params[$source];
          break;
        }
      }
    }

    if (!empty($params['billingCountry'])) {
      $country = $params['billingCountry'];
      $billing['country'] = preg_match('/^[A-Za-z]{2}$/', $country)
        ? strtoupper($country)
        : CRM_Core_PseudoConstant::countryIsoCode($country);
    }

    return self::build($billing);
  }

}
