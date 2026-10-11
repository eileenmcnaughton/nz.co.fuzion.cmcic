<?php

declare(strict_types = 1);

use PHPUnit\Framework\TestCase;

final class CmcicPaymentStatusTest extends TestCase {

  public function testMapsStateToCheckoutStatus(): void {
    self::assertSame('success', CRM_Core_Payment_CmcicPaymentStatus::getCheckoutStatus('PA'));
    self::assertSame('cancel', CRM_Core_Payment_CmcicPaymentStatus::getCheckoutStatus('AN'));
    self::assertSame('fail', CRM_Core_Payment_CmcicPaymentStatus::getCheckoutStatus('RE'));
    self::assertSame('pending', CRM_Core_Payment_CmcicPaymentStatus::getCheckoutStatus('UNKNOWN'));
  }

  public function testParsesXmlStatusResponseViaMockHttpClient(): void {
    $mockXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<etatpaiement>
  <etat>PA</etat>
  <numauto>123456</numauto>
  <montantrecouvre>150.00EUR</montantrecouvre>
  <recredits>
    <total>50.00EUR</total>
  </recredits>
</etatpaiement>
XML;

    $mockHttpClient = static function (string $endpoint, array $fields) use ($mockXml): string {
      return $mockXml;
    };

    $fields = [
      'version' => '2.0',
      'TPE' => '1234567',
      'date' => '06/08/2026',
      'montant' => '150.00EUR',
      'reference' => '85816',
      'societe' => 'acme',
    ];

    $result = CRM_Core_Payment_CmcicPaymentStatus::query(
      $fields,
      '00112233445566778899AABBCCDDEEFF00112233',
      'sha1',
      'https://payment-api.e-i.com/test/etatpaiement.cgi',
      $mockHttpClient
    );

    self::assertSame('PA', $result['state']);
    self::assertSame('123456', $result['authorization_number']);
    self::assertSame(150.00, $result['captured_amount']);
    self::assertSame(50.00, $result['recredits_total']);
  }

  public function testThrowsExceptionOnInvalidXml(): void {
    $mockHttpClient = static function (): string {
      return 'INVALID XML';
    };

    $fields = [
      'version' => '2.0',
      'TPE' => '1234567',
      'date' => '06/08/2026',
      'montant' => '150.00EUR',
      'reference' => '85816',
      'societe' => 'acme',
    ];

    $this->expectException(CRM_Core_Exception::class);
    CRM_Core_Payment_CmcicPaymentStatus::query(
      $fields,
      '00112233445566778899AABBCCDDEEFF00112233',
      'sha1',
      'https://payment-api.e-i.com/test/etatpaiement.cgi',
      $mockHttpClient
    );
  }

  public function testParsesCaptureDetailsForCaptureSpecificRefund(): void {
    $mockXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<etatpaiement>
  <etat>PA</etat>
  <numauto>123456</numauto>
  <montantrecouvre>150.00EUR</montantrecouvre>
  <recouvrements>
    <recouvrement>
      <resultat>1</resultat>
      <montant>150.00EUR</montant>
      <date_remise>2026-08-10</date_remise>
      <numero_autorisation>123456</numero_autorisation>
      <recredits>
        <recredit>
          <date_GMT>2026-08-11</date_GMT>
          <heure_GMT>10:26:06</heure_GMT>
          <montant_recredite>10.00EUR</montant_recredite>
          <methode>IP</methode>
        </recredit>
        <recredit>
          <date_GMT>2026-08-12</date_GMT>
          <heure_GMT>09:15:00</heure_GMT>
          <montant_recredite>5.25EUR</montant_recredite>
          <methode>TB</methode>
        </recredit>
      </recredits>
    </recouvrement>
  </recouvrements>
</etatpaiement>
XML;

    $result = CRM_Core_Payment_CmcicPaymentStatus::query(
      [
        'version' => '2.0',
        'TPE' => '1234567',
        'date' => '06/08/2026',
        'montant' => '150.00EUR',
        'reference' => '85816',
        'societe' => 'acme',
      ],
      '00112233445566778899AABBCCDDEEFF00112233',
      'sha1',
      'https://payment-api.e-i.com/test/etatpaiement.cgi',
      static function () use ($mockXml): string {
        return $mockXml;
      }
    );

    self::assertSame(150.00, $result['captures'][0]['amount']);
    self::assertSame('2026-08-10', $result['captures'][0]['date_remise']);
    self::assertSame('123456', $result['captures'][0]['authorization_number']);
    self::assertSame(15.25, $result['captures'][0]['recredits_total']);
  }

}
