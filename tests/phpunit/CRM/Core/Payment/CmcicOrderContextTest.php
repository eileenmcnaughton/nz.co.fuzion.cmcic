<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CmcicOrderContextTest extends TestCase {

  public function testBuildsAllowlistedBillingContext(): void {
    $context = CRM_Core_Payment_CmcicOrderContext::build([
      'firstName' => 'Ada',
      'lastName' => 'Lovelace',
      'addressLine1' => '12 Rue de la Paix',
      'city' => 'Paris',
      'postalCode' => '75002',
      'country' => 'FR',
      'email' => 'ada@example.test',
      'internal_note' => 'must never leave CiviCRM',
    ]);

    self::assertSame([
      'billing' => [
        'firstName' => 'Ada',
        'lastName' => 'Lovelace',
        'addressLine1' => '12 Rue de la Paix',
        'city' => 'Paris',
        'postalCode' => '75002',
        'country' => 'FR',
        'email' => 'ada@example.test',
      ],
    ], json_decode(base64_decode($context), TRUE, 512, JSON_THROW_ON_ERROR));
  }

  public function testRejectsIncompleteBillingContext(): void {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Missing required Monetico billing field: addressLine1');

    CRM_Core_Payment_CmcicOrderContext::build([
      'city' => 'Paris',
      'postalCode' => '75002',
      'country' => 'FR',
    ]);
  }

  public function testExcludesPhoneFromOrderContext(): void {
    $context = CRM_Core_Payment_CmcicOrderContext::buildFromPaymentParams([
      'billingStreetAddress' => '12 Rue de la Paix',
      'billingCity' => 'Paris',
      'billingPostalCode' => '75002',
      'billingCountry' => 'FR',
      'email' => 'ada@example.test',
      'phone' => '06 12 34 56 78',
    ]);

    $decoded = json_decode(base64_decode($context), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertArrayNotHasKey('phone', $decoded['billing']);
  }
}
