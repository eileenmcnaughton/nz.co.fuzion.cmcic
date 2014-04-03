<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return array (
  0 =>
  array (
    'name' => 'cmcic',
    'entity' => 'PaymentProcessorType',
    'params' =>
    array (
      'version' => 3,
      'name' => 'cmcic',
      'title' => 'CMCIC',
      'description' => 'CMCIC (CyberMut)',
      'user_name_label' => 'POS terminal number',
      'password_label' => 'Merchant security key',
      'signature_label' => 'Site code',
      'subject_label' => 'algorithm - defaults to md5',
      'class_name' => 'Payment_Cmcic',
      'billing_mode' => 1,
      'url_site_default' => 'https://ssl.paiement.cic-banques.fr/test/paiement.cgi',
    ),
  ),
);