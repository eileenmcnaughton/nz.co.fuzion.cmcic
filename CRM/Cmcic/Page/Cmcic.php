<?php

class CRM_Cmcic_Page_Cmcic extends CRM_Core_Page{
  function run() {
    $checkout = CRM_Core_Session::singleton()->get('checkout', 'cmcic');
    if (empty($checkout['fields']) || empty($checkout['url'])) {
      CRM_Core_Error::fatal(ts('Unable to start the Monetico checkout.'));
    }

    CRM_Core_Session::singleton()->set('checkout', NULL, 'cmcic');
    $this->assign('fields', $checkout['fields']);
    $this->assign('url', $checkout['url']);
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
