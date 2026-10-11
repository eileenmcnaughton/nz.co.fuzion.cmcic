<?php

declare(strict_types = 1);

/**
 * Short-lived, cookie-independent state for a Hosted Fields attempt.
 *
 * This experimental store uses CiviCRM's durable long cache. A permanent API4
 * attempt entity can replace it without changing the browser contract.
 */
final class CRM_Cmcic_HostedFieldsStore {
  private const PREFIX = 'cmcic_hosted_fields_';

  /**
   * Store a Hosted Fields attempt.
   *
   * @param string $attemptId Attempt identifier.
   * @param array<string,mixed> $attempt Attempt data.
   */
  public static function set(string $attemptId, array $attempt): void {
    self::assertId($attemptId);
    Civi::cache('long')->set(
        self::PREFIX . $attemptId,
        $attempt,
        DateInterval::createFromDateString('1 hour')
    );
  }

  /**
   * @return array<string,mixed>
   */
  public static function get(string $attemptId): array {
    self::assertId($attemptId);
    $attempt = Civi::cache('long')->get(self::PREFIX . $attemptId);
    if (!is_array($attempt)) {
      throw new CRM_Core_Exception(ts('The Monetico payment attempt has expired.'));
    }
    return $attempt;
  }

  public static function delete(string $attemptId): void {
    self::assertId($attemptId);
    Civi::cache('long')->delete(self::PREFIX . $attemptId);
  }

  private static function assertId(string $attemptId): void {
    if (!preg_match('/^[a-f0-9]{32}$/', $attemptId)) {
      throw new CRM_Core_Exception(ts('Invalid Monetico payment attempt.'));
    }
  }

}
