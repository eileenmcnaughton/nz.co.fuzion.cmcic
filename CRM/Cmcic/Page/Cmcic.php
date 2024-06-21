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
    $smarty = CRM_Core_Smarty::singleton();
    echo CRM_Utils_String::parseOneOffStringThroughSmarty($this->getText());
    die;
    parent::run();
  }

  /**
   * we are trying this quick retrieval in the hope of a quicker form
   * @return string
   */
  function getText() {
    return "<p>" . ts('Please Click the pay now button if you are not automatically redirected') . '</p>
<form method="post" id="form" name="CMCICFormulaire"
target="_top" action="{$url}">
{foreach from=$fields key=k item=field}
  <input type="hidden" name="{$k}" value="{$field}">
{/foreach}
<input type="submit" name="bouton" value="' . ts('Pay Now') . '">
</form>
<script type="text/javascript">
document.getElementById("form").submit();
</script>';
  }
}