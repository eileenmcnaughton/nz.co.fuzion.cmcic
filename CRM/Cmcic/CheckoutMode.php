<?php

/**
 * Resolve the Monetico checkout experience independently from the CiviCRM UI.
 *
 * QuickForm, Event, Webform and Afform must all use this contract. New modes
 * are declared here, but are not offered until their complete browser and
 * server flows are implemented.
 */
final class CRM_Cmcic_CheckoutMode {
  public const HOSTED_PAGE = 'hosted_page';

  public const HOSTED_FIELDS = 'hosted_fields';

  /**
   * Modes that administrators can select.
   *
   * @return array<string, string>
   */
  public static function getOptions(): array {
    return [
      self::HOSTED_PAGE => ts('Monetico hosted payment page'),
      self::HOSTED_FIELDS => ts('Monetico Hosted Fields (experimental)'),
    ];
  }

  /**
   * Return the configured mode, preserving known experimental values so they
   * fail explicitly instead of silently changing the payment experience.
   */
  public static function getConfiguredMode(): string {
    $mode = (string) \Civi::settings()->get('cmcic_checkout_mode');
    return self::isKnown($mode) ? $mode : self::HOSTED_PAGE;
  }

  public static function isKnown(string $mode): bool {
    return in_array($mode, [self::HOSTED_PAGE, self::HOSTED_FIELDS], TRUE);
  }

  public static function isImplemented(string $mode): bool {
    return array_key_exists($mode, self::getOptions());
  }

  /**
   * Prevent unknown modes from receiving payments.
   */
  public static function assertImplemented(string $mode): void {
    if (!self::isImplemented($mode)) {
      throw new CRM_Core_Exception(ts('The selected Monetico checkout mode is not implemented yet.'));
    }
  }

}
