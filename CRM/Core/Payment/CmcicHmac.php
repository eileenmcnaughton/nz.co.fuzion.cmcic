<?php

/**
 * Builds Monetico DSP2 MAC values.
 */
class CRM_Core_Payment_CmcicHmac {

  /**
   * Calculate a MAC from all fields, ordered by their ASCII field names.
   *
   * @param array $fields
   * @param string $key
   * @param string $algorithm
   *
   * @return string
   */
  public static function calculate($fields, $key, $algorithm = 'sha1') {
    ksort($fields, SORT_STRING);
    $parts = array();
    foreach ($fields as $name => $value) {
      $parts[] = $name . '=' . $value;
    }

    return hash_hmac($algorithm, implode('*', $parts), $key);
  }

}
