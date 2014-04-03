<?php

class CRM_Cmcic_Page_Cmcic extends CRM_Core_Page{
  function run() {
    $params = array();
    $fields = array(
      'version',
      'TPE',
      'date',
      'montant',
      'reference',
      'url_retour',
      'url_retour_ok',
      'url_retour_err',
      'lgue',
      'societe',
      'texte-libre',
      'mail',
      'MAC',
    );
    foreach ($fields as $field) {
      $params[$field] = CRM_Utils_Request::retrieve($field, 'String');
    }
    $this->assign('fields', $params);
    $this->assign('url', CRM_Utils_Request::retrieve('submit_to', 'String'));
    parent::run();
  }
}