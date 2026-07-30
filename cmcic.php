<?php

require_once 'cmcic.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function cmcic_civicrm_config(&$config) {
  _cmcic_civix_civicrm_config($config);

  if (!cmcic_supports_afform_checkout()) {
    return;
  }

  Civi::dispatcher()->addListener('civi.checkout.options', 'cmcic_register_afform_checkout_options');
}

/**
 * Only load the optional Afform checkout integration on supported CiviCRM.
 *
 * @return bool
 */
function cmcic_supports_afform_checkout() {
  return version_compare(CRM_Utils_System::version(), '6.14', '>=')
    && interface_exists('Civi\\Checkout\\CheckoutOptionInterface')
    && interface_exists('Civi\\Checkout\\AfformCheckoutOptionInterface')
    && class_exists('Civi\\Checkout\\CheckoutSession')
    && class_exists('Civi\\Afform\\Event\\AfformValidateEvent')
    && class_exists('Civi\\Api4\\PaymentProcessor')
    && class_exists('Civi\\Payment\\System');
}

/**
 * Publish one checkout option for each active test/live Monetico pair.
 *
 * @param object $event
 */
function cmcic_register_afform_checkout_options($event) {
  if (!cmcic_supports_afform_checkout()) {
    return;
  }

  $processors = Civi\Api4\PaymentProcessor::get(FALSE)
    ->addWhere('class_name', '=', 'Payment_Cmcic')
    ->addWhere('is_active', '=', TRUE)
    ->addWhere('is_test', 'IN', array(TRUE, FALSE))
    ->execute();

  $pairs = array();
  foreach ($processors as $processor) {
    $pairs[$processor['name']][$processor['is_test'] ? 'test' : 'live'] = $processor;
  }

  foreach ($pairs as $name => $pair) {
    if (empty($pair['live']) || empty($pair['test'])) {
      continue;
    }
    $event->options['cmcic_hosted_checkout_' . $name] = new Civi\Cmcic\CheckoutOption\CmcicHostedCheckout(
      $pair['live'] ?? NULL,
      $pair['test'] ?? NULL
    );
  }
}

/**
 * Implementation of hook_civicrm_install
 */
function cmcic_civicrm_install() {
  return _cmcic_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_enable
 */
function cmcic_civicrm_enable() {
  return _cmcic_civix_civicrm_enable();
}
