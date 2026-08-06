<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CmcicHmacTest extends TestCase {

  public function testCalculatesDsp2MacWithAsciiSortedFields(): void {
    $fields = [
      'reference' => '85816',
      'TPE' => '1234567',
      'montant' => '12.12EUR',
    ];
    $key = '00112233445566778899AABBCCDDEEFF00112233';

    $expected = hash_hmac(
      'sha1',
      'TPE=1234567*montant=12.12EUR*reference=85816',
      hex2bin($key)
    );

    self::assertSame($expected, CRM_Core_Payment_CmcicHmac::calculate($fields, $key));
  }

  public function testAcceptsAlreadyPackedSecurityKey(): void {
    $fields = ['reference' => '85816'];
    $binaryKey = hex2bin('00112233445566778899AABBCCDDEEFF00112233');

    self::assertSame(
      hash_hmac('sha1', 'reference=85816', $binaryKey),
      CRM_Core_Payment_CmcicHmac::calculate($fields, $binaryKey)
    );
  }
}
