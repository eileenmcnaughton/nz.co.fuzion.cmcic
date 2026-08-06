<?php

declare(strict_types=1);

/**
 * Builds Monetico DSP2 MAC values compliant with Monetico official SDK / Magento kit.
 */
class CRM_Core_Payment_CmcicHmac {

  /**
   * Calculate a MAC from all fields, ordered by their ASCII field names.
   */
  public static function calculate(array $fields, string $key, string $algorithm = 'sha1'): string {
    ksort($fields, SORT_STRING);
    $parts = array();
    foreach ($fields as $name => $value) {
      $parts[] = $name . '=' . $value;
    }
    $stringToSeal = implode('*', $parts);

    $binaryKey = self::ensureBinaryKey($key);

    return hash_hmac($algorithm, $stringToSeal, $binaryKey);
  }

  /**
   * Safely obtain the binary key, handling both pre-packed binary keys (from Cmcic::getKey())
   * and raw hexadecimal strings.
   */
  private static function ensureBinaryKey(string $key): string {
    // If the key is already binary (20 bytes from pack('H*') in Cmcic::getKey()), use it as-is
    if (strlen($key) === 20 && !self::isHex($key)) {
      return $key;
    }

    // If key is a valid hex string, convert it to binary
    if (self::isHex($key)) {
      $bin = @hex2bin($key);
      if ($bin !== FALSE) {
        return $bin;
      }
    }

    // Handle legacy Monetico hex keys (P/M replacement) if raw hex string was passed
    $usableKey = self::getUsableKey($key);
    $bin = @hex2bin($usableKey);
    return ($bin !== FALSE) ? $bin : $key;
  }

  /**
   * Normalize legacy Monetico hex keys (handling P/M characters).
   */
  private static function getUsableKey(string $key): string {
    if (self::isHex($key)) {
      return $key;
    }

    $key = strtoupper($key);
    if (isset($key[39]) && $key[39] === 'M') {
      $key[39] = '0';
      return $key;
    }
    if (isset($key[38]) && $key[38] === 'P') {
      $key[38] = '9';
      return $key;
    }

    return $key;
  }

  /**
   * Safe check for hexadecimal string, with fallback if ext-ctype is missing.
   */
  private static function isHex(string $key): bool {
    if (function_exists('ctype_xdigit')) {
      return ctype_xdigit($key);
    }
    return (bool) preg_match('/^[0-9a-fA-F]+$/', $key);
  }

}
