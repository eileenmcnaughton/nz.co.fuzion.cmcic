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
    $contactId = !empty($params['contactID']) ? $params['contactID'] : (!empty($params['contact_id']) ? $params['contact_id'] : NULL);
    if ($contactId && (empty($params['billingStreetAddress']) || empty($params['billingCity']) || empty($params['billingPostalCode']) || empty($params['billingCountry']))) {
      $address = \Civi\Api4\Address::get(FALSE)
        ->addSelect(
          'street_address',
          'supplemental_address_1',
          'supplemental_address_2',
          'city',
          'postal_code',
          'country_id'
        )
        ->addWhere('contact_id', '=', $contactId)
        ->addOrderBy('is_billing', 'DESC')
        ->addOrderBy('is_primary', 'DESC')
        ->execute()
        ->first();
      if ($address) {
        foreach (array(
          'billingStreetAddress' => 'street_address',
          'billingSupplementalAddress1' => 'supplemental_address_1',
          'billingSupplementalAddress2' => 'supplemental_address_2',
          'billingCity' => 'city',
          'billingPostalCode' => 'postal_code',
          'billingCountry' => 'country_id',
        ) as $paymentField => $addressField) {
          if (empty($params[$paymentField]) && !empty($address[$addressField])) {
            $params[$paymentField] = $address[$addressField];
          }
        }
      }
    }

    $billing = array();
    $map = array(
      'firstName' => array('firstName', 'first_name'),
      'lastName' => array('lastName', 'last_name'),
      'addressLine1' => array('billingStreetAddress', 'street_address', 'street_address-1'),
      'addressLine2' => array('billingSupplementalAddress1', 'supplemental_address_1-1'),
      'addressLine3' => array('billingSupplementalAddress2', 'supplemental_address_2-1'),
      'city' => array('billingCity', 'city', 'city-1'),
      'postalCode' => array('billingPostalCode', 'postal_code', 'postal_code-1'),
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

    $country = !empty($params['billingCountry']) ? $params['billingCountry'] : (!empty($params['country-1']) ? $params['country-1'] : NULL);
    if ($country) {
      $billing['country'] = preg_match('/^[A-Za-z]{2}$/', $country)
        ? strtoupper($country)
        : CRM_Core_PseudoConstant::countryIsoCode($country);
    }

    return self::build($billing);
  }

}
