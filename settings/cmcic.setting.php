<?php

return [
  'cmcic_enable_refunds' => [
    'group_name' => 'Monetico Settings',
    'group' => 'cmcic',
    'name' => 'cmcic_enable_refunds',
    'type' => 'Boolean',
    'default' => FALSE,
    'add_to_setting_form' => FALSE,
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => 'Enable online refund support via Monetico recredit_paiement API.',
    'help_text' => 'Requires merchant server IP whitelisting with Monetico.',
  ],
];
