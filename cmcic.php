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
function cmcic_supports_afform_checkout(): bool {
  return version_compare(CRM_Utils_System::version(), '6.14', '>=')
    && interface_exists('Civi\Checkout\CheckoutOptionInterface')
    && interface_exists('Civi\Checkout\AfformCheckoutOptionInterface')
    && class_exists('Civi\Checkout\CheckoutSession')
    && class_exists('Civi\Afform\Event\AfformValidateEvent')
    && class_exists('Civi\Api4\PaymentProcessor')
    && class_exists('Civi\Payment\System');
}

/**
 * Safely check if the optional MJWShared extension is active.
 *
 * @return bool
 */
function cmcic_supports_mjwshared(): bool {
  if (class_exists('CRM_Mjwshared_Bao_Mjwshared')) {
    return TRUE;
  }
  if (class_exists('CRM_Extension_System')) {
    $manager = CRM_Extension_System::singleton()->getManager();
    return method_exists($manager, 'isEnabled') && $manager->isEnabled('mjwshared');
  }
  return FALSE;
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
 * Automatically load Monetico Drupal AJAX helper script when building forms.
 *
 * @param string $formName
 * @param CRM_Core_Form $form
 */
function cmcic_civicrm_buildForm($formName, &$form) {
  if (class_exists('CRM_Core_Resources')) {
    CRM_Core_Resources::singleton()->addScriptFile('nz.co.fuzion.cmcic', 'js/civicrmCmcic.js');
  }
}

/**
 * Add Monetico CGI2 server notification URL alert to System Status (civicrm/a/#/status).
 *
 * @param array $messages
 * @param array $statusNames
 * @param bool $includeDisabled
 */
function cmcic_civicrm_check(&$messages, $statusNames = [], $includeDisabled = FALSE): void {
  if (!class_exists('Civi\Api4\PaymentProcessor') || !class_exists('CRM_Utils_System') || !class_exists('CRM_Utils_Check_Message')) {
    return;
  }

  try {
    $processors = \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addSelect('id', 'title', 'is_test')
      ->addWhere('class_name', '=', 'Payment_Cmcic')
      ->addWhere('is_active', '=', TRUE)
      ->execute();
  }
  catch (\Throwable $e) {
    return;
  }

  foreach ($processors as $processor) {
    $url = CRM_Utils_System::getNotifyUrl(
      'civicrm/payment/ipn/' . $processor['id'],
      [],
      TRUE,
      NULL,
      FALSE,
      TRUE
    );

    if (!str_starts_with($url, 'https://')) {
      $messages[] = new CRM_Utils_Check_Message(
        'cmcic_ipn_http_warning_' . $processor['id'],
        ts(
          'Monetico server confirmation URL for %1 (%2) is insecure HTTP: <code>%3</code>. '
          . 'Monetico requires an HTTPS URL. HTTP redirects strip confirmation parameters and fail CGI2 IPN processing.',
          [
            1 => $processor['title'],
            2 => $processor['is_test'] ? ts('test') : ts('production'),
            3 => htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
          ]
        ),
        ts('Monetico: insecure HTTP return URL'),
        \Psr\Log\LogLevel::ERROR,
        'fa-exclamation-triangle'
      );
    }

    $messages[] = new CRM_Utils_Check_Message(
      'cmcic_ipn_configuration_' . $processor['id'],
      ts(
        'Configure this Monetico server confirmation URL for %1 (%2): <code>%3</code>. '
        . 'CiviCRM cannot verify the configuration on Monetico.',
        [
          1 => $processor['title'],
          2 => $processor['is_test'] ? ts('test') : ts('production'),
          3 => htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
        ]
      ),
      ts('Monetico: server confirmation URL'),
      \Psr\Log\LogLevel::NOTICE,
      'fa-credit-card'
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
