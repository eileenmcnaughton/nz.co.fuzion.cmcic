<?php

require_once 'cmcic.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function cmcic_civicrm_config(&$config) {
  _cmcic_civix_civicrm_config($config);
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
