<?php

/**
 * Builds the minimal Monetico DSP2 order context.
 */
class CRM_Core_Payment_CmcicOrderContext {

  /**
   * Build the base64-encoded context from an allowlisted billing address.
   *
   * @param array $billing Allowlisted billing address.
   * @param mixed $shoppingCart Optional shopping cart.
   *
   * @return string
   * @throws InvalidArgumentException
   */
  public static function build($billing, $shoppingCart = NULL) {
    $required = ['addressLine1', 'city', 'postalCode', 'country'];
    foreach ($required as $field) {
      if (empty($billing[$field])) {
        throw new InvalidArgumentException('Missing required Monetico billing field: ' . $field);
      }
    }

    $allowed = [
      'civility', 'name', 'firstName', 'lastName', 'middleName', 'address',
      'addressLine1', 'addressLine2', 'addressLine3', 'city', 'postalCode',
      'country', 'stateOrProvince', 'countrySubdivision', 'email', 'phone',
      'mobilePhone', 'homePhone', 'workPhone',
    ];
    $contextBilling = [];
    foreach ($allowed as $field) {
      if (isset($billing[$field]) && $billing[$field] !== '') {
        $contextBilling[$field] = $billing[$field];
      }
    }

    $context = ['billing' => $contextBilling];
    if ($shoppingCart) {
      $context['shoppingCart'] = $shoppingCart;
    }

    return base64_encode(json_encode($context, JSON_UNESCAPED_UNICODE));
  }

  /**
   * Build the order context from CiviCRM payment parameters.
   *
   * @param array $params
   *
   * @return string
   */
  public static function buildFromPaymentParams($params) {
    $contactId = !empty($params['contactID'])
            ? $params['contactID']
            : (!empty($params['contact_id']) ? $params['contact_id'] : NULL);
    $billingAddressIsIncomplete = empty($params['billingStreetAddress'])
            || empty($params['billingCity'])
            || empty($params['billingPostalCode'])
            || empty($params['billingCountry']);
    if ($contactId && $billingAddressIsIncomplete) {
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
        foreach (
        [
          'billingStreetAddress' => 'street_address',
          'billingSupplementalAddress1' => 'supplemental_address_1',
          'billingSupplementalAddress2' => 'supplemental_address_2',
          'billingCity' => 'city',
          'billingPostalCode' => 'postal_code',
          'billingCountry' => 'country_id',
        ] as $paymentField => $addressField
        ) {
          if (empty($params[$paymentField]) && !empty($address[$addressField])) {
            $params[$paymentField] = $address[$addressField];
          }
        }
      }
    }

    $billing = [];
    $map = [
      'firstName' => ['firstName', 'first_name'],
      'lastName' => ['lastName', 'last_name'],
      'addressLine1' => ['billingStreetAddress', 'street_address', 'street_address-1'],
      'addressLine2' => ['billingSupplementalAddress1', 'supplemental_address_1-1'],
      'addressLine3' => ['billingSupplementalAddress2', 'supplemental_address_2-1'],
      'city' => ['billingCity', 'city', 'city-1'],
      'postalCode' => ['billingPostalCode', 'postal_code', 'postal_code-1'],
      'email' => ['email', 'email-Primary', 'email-5'],
    ];
    foreach ($map as $target => $sources) {
      foreach ($sources as $source) {
        if (isset($params[$source]) && $params[$source] !== '') {
          $billing[$target] = $params[$source];
          break;
        }
      }
    }

    $country = !empty($params['billingCountry'])
            ? $params['billingCountry']
            : (!empty($params['country-1']) ? $params['country-1'] : NULL);
    if ($country) {
      $billing['country'] = preg_match('/^[A-Za-z]{2}$/', $country)
            ? strtoupper($country)
            : CRM_Core_PseudoConstant::countryIsoCode($country);
    }

    $contributionId = !empty($params['contributionID'])
            ? $params['contributionID']
            : ($params['contribution_id'] ?? NULL);

    return self::build($billing, self::buildShoppingCart($contributionId));
  }

  /**
   * Build an optional DSP2 shopping cart from CiviCRM line items.
   *
   * The order context supplements a payment request. An incomplete or
   * non-representable cart must therefore never prevent a contribution from
   * being paid.
   *
   * @param int|null $contributionId
   *
   * @return array|null
   */
  public static function buildShoppingCart($contributionId) {
    if (!$contributionId) {
      return NULL;
    }

    try {
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('total_amount')
        ->addWhere('id', '=', $contributionId)
        ->execute()
        ->first();
      $expectedAmount = self::toMinorUnits($contribution['total_amount'] ?? NULL);
      if ($expectedAmount === NULL) {
        return NULL;
      }

      $lineItems = \Civi\Api4\LineItem::get(FALSE)
        ->addSelect('*', 'price_field_id:label', 'price_field_value_id:label')
        ->addWhere('contribution_id', '=', $contributionId)
        ->execute();
    }
    catch (\Throwable) {
      return NULL;
    }

    $shoppingCartItems = [];
    $cartAmount = 0;
    foreach ($lineItems as $lineItem) {
      $shoppingCartItem = self::buildShoppingCartItem($lineItem);
      if ($shoppingCartItem === NULL) {
        return NULL;
      }

      $shoppingCartItems[] = $shoppingCartItem;
      $cartAmount += $shoppingCartItem['unitPrice'] * $shoppingCartItem['quantity'];
    }

    if (!$shoppingCartItems || $cartAmount !== $expectedAmount) {
      return NULL;
    }

    return ['shoppingCartItems' => $shoppingCartItems];
  }

  /**
   * Convert one CiviCRM line item to Monetico's gross unit-price format.
   *
   * CiviCRM stores line_total and tax_amount for the whole line. Monetico
   * expects a unit price, so the gross line amount must be divided by the
   * quantity only after tax has been included.
   *
   * @param array $lineItem
   *
   * @return array|null
   */
  private static function buildShoppingCartItem($lineItem) {
    $quantity = self::toQuantity($lineItem['qty'] ?? NULL);
    $lineTotal = self::toMinorUnits($lineItem['line_total'] ?? NULL);
    $taxAmount = self::toMinorUnits($lineItem['tax_amount'] ?? 0);
    $name = self::getLineItemName($lineItem);
    if ($quantity === NULL || $lineTotal === NULL || $taxAmount === NULL || !$name) {
      return NULL;
    }

    $grossLineAmount = $lineTotal + $taxAmount;
    if ($grossLineAmount < 0 || $grossLineAmount % $quantity !== 0) {
      return NULL;
    }

    return [
      'name' => $name,
      'unitPrice' => intdiv($grossLineAmount, $quantity),
      'quantity' => $quantity,
    ];
  }

  /**
   * Get the useful donor-facing title for a CiviCRM line item.
   *
   * @param array $lineItem
   *
   * @return string|null
   */
  private static function getLineItemName($lineItem) {
    foreach (['price_field_value_id:label', 'price_field_id:label', 'label'] as $field) {
      if (!empty($lineItem[$field]) && is_scalar($lineItem[$field])) {
        return trim((string) $lineItem[$field]);
      }
    }

    return NULL;
  }

  /**
   * Convert a decimal CiviCRM amount to the Monetico minor-unit format.
   *
   * @param mixed $amount
   *
   * @return int|null
   */
  private static function toMinorUnits($amount) {
    if (!is_numeric($amount)) {
      return NULL;
    }

    return (int) round((float) $amount * 100);
  }

  /**
   * Return a positive integer quantity, or NULL if it cannot be represented.
   *
   * @param mixed $quantity
   *
   * @return int|null
   */
  private static function toQuantity($quantity) {
    if (!is_numeric($quantity)) {
      return NULL;
    }

    $integerQuantity = (int) $quantity;
    return $integerQuantity > 0 && (float) $quantity === (float) $integerQuantity ? $integerQuantity : NULL;
  }

}
