<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../cmcic.php';

final class CmcicSupportsTest extends TestCase {

  public function testAfformCheckoutSupportGuardReturnsBoolean(): void {
    $result = cmcic_supports_afform_checkout();
    self::assertIsBool($result);
  }

  public function testMjwsharedSupportGuardReturnsBoolean(): void {
    $result = cmcic_supports_mjwshared();
    self::assertIsBool($result);
  }

  public function testCivicrmCheckHookExecutesWithoutErrors(): void {
    $messages = [];
    cmcic_civicrm_check($messages);
    self::assertIsArray($messages);
  }
}
