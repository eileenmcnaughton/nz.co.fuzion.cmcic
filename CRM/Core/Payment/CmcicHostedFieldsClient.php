<?php

declare(strict_types = 1);

/**
 * Minimal server-side client for the Monetico Hosted Fields APIs.
 */
final class CRM_Core_Payment_CmcicHostedFieldsClient {
  private const TEST_API_BASE = 'https://payment-api.e-i.com/test/';

  private const LIVE_API_BASE = 'https://payment-api.e-i.com/';

  /**
   * Generate the short-lived token consumed by hostedfields.js.
   *
   * @param CRM_Core_Payment_Cmcic $processor Monetico payment processor.
   *
   * @return array<string,mixed>
   */
  public static function initializePaymentMean(CRM_Core_Payment_Cmcic $processor): array {
    $configuration = $processor->getPaymentProcessor();
    $body = [
      'action' => 'InitializePaymentMean',
      'context' => [
        'use_case' => 'Payment',
      ],
      'datetime' => date('Y-m-d\TH:i:s'),
      'merchant_configuration' => [
        'configuration' => (string) ($configuration['signature'] ?? ''),
        'language' => $processor->getLanguage(),
        'point_of_sale' => (string) ($configuration['user_name'] ?? ''),
      ],
    ];

    $result = self::postJson(
        $processor,
        'paymentmeantokengenerator.cgi',
        $body
    );
    if ((int) ($result['return_code'] ?? -1) !== 0 || empty($result['token'])) {
      throw new CRM_Core_Exception(sprintf(
        'Monetico Hosted Fields token generation failed (return_code=%s): %s',
        (string) ($result['return_code'] ?? 'missing'),
        (string) ($result['error_message'] ?? 'unexpected response')
      ));
    }

    return $result;
  }

  /**
   * Submit a payment using the card data attached to a Hosted Fields token.
   *
   * @param CRM_Core_Payment_Cmcic $processor Monetico payment processor.
   * @param array<string,mixed> $checkout Checkout data.
   *
   * @return array<string,mixed>
   */
  public static function requestPayment(CRM_Core_Payment_Cmcic $processor, array $checkout): array {
    $configuration = $processor->getPaymentProcessor();
    $body = [
      'merchant_configuration' => [
        'point_of_sale' => (string) ($configuration['user_name'] ?? ''),
        'version' => '3.0',
        'language' => $processor->getLanguage(),
        'configuration' => (string) ($configuration['signature'] ?? ''),
      ],
      'order' => [
        'date' => date('Y-m-d\TH:i:s'),
        'customer' => [
          'mail' => (string) ($checkout['email'] ?? ''),
        ],
        'context' => $checkout['context'],
      ],
      'payment' => [
        'transaction_initiator' => 'cardholder',
        'reference' => (string) $checkout['contribution_id'],
        'payment_mean' => [
          'payment_mean_token' => (string) $checkout['payment_mean_token'],
        ],
        'amount' => [
          'value' => (int) $checkout['amount_minor'],
          'currency' => (string) $checkout['currency'],
          'exponent' => 2,
        ],
      ],
      'authentication' => [
        'merchant_preference' => 'no_preference',
        'merchant_redirection_url' => (string) $checkout['hosted_fields_url'],
        'challenge_window_size' => '500x600',
      ],
    ];

    return self::postJson($processor, 'paymentservice.cgi', $body);
  }

  /**
   * Continue a PaymentService request after a browser-side 3-D Secure step.
   * Continuation calls are deliberately not MAC-signed per Monetico's API.
   *
   * @param CRM_Core_Payment_Cmcic $processor Monetico payment processor.
   * @param array<string,mixed> $body Continuation request body.
   *
   * @return array<string,mixed>
   */
  public static function continuePayment(CRM_Core_Payment_Cmcic $processor, array $body): array {
    return self::postJson($processor, 'paymentservice.cgi', $body, FALSE);
  }

  public static function getSdkUrl(bool $isTest): string {
    return $isTest
        ? 'https://p.monetico-services.com/test/hostedfields/hostedfields.js'
        : 'https://p.monetico-services.com/hostedfields/hostedfields.js';
  }

  /**
   * Send a JSON request to Monetico.
   *
   * @param CRM_Core_Payment_Cmcic $processor Monetico payment processor.
   * @param string $path Relative API path.
   * @param array<string,mixed> $data Request data.
   * @param bool $signed Whether to sign the request body.
   *
   * @return array<string,mixed>
   */
  private static function postJson(
        CRM_Core_Payment_Cmcic $processor,
        string $path,
        array $data,
        bool $signed = TRUE
    ): array {
    try {
      $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    catch (JsonException $e) {
      throw new CRM_Core_Exception('Unable to encode the Monetico API request: ' . $e->getMessage());
    }

    $configuration = $processor->getPaymentProcessor();
    $isTest = !empty($configuration['is_test']);
    $client = new \GuzzleHttp\Client([
      'connect_timeout' => 5,
      'timeout' => 20,
      'verify' => TRUE,
    ]);

    try {
      $headers = [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json; charset=utf-8',
      ];
      if ($signed) {
        $headers['MAC'] = CRM_Core_Payment_CmcicHmac::calculateBody(
        $body,
        $processor->getKey(),
        $processor->getAlgorithm()
        );
      }
      $response = $client->post(($isTest ? self::TEST_API_BASE : self::LIVE_API_BASE) . $path, [
        'body' => $body,
        'headers' => $headers,
        'http_errors' => FALSE,
      ]);
    }
    catch (Throwable $e) {
      throw new CRM_Core_Exception('Monetico Hosted Fields HTTP request failed: ' . $e->getMessage());
    }

    $responseBody = (string) $response->getBody();
    try {
      $result = json_decode($responseBody, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (JsonException) {
      throw new CRM_Core_Exception(sprintf(
        'Monetico Hosted Fields returned invalid JSON (HTTP %d).',
        $response->getStatusCode()
        ));
    }
    if (!is_array($result)) {
      throw new CRM_Core_Exception('Monetico Hosted Fields returned an unexpected response.');
    }
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      throw new CRM_Core_Exception(sprintf(
        'Monetico Hosted Fields request failed (HTTP %d): %s',
        $response->getStatusCode(),
        (string) ($result['error_message'] ?? 'unexpected response')
      ));
    }

    return $result;
  }

}
