<p>{ts}Please Click the pay now button if you are not automatically redirected{/ts}</p>
<form method="post" id="form" name="CMCICFormulaire"
target="_top" action="{$url}">
{foreach from=$fields key=k item=field}
  <input type="hidden" name="{$k}" value="{$field}">
{/foreach}
<input type="submit" name="bouton" value="{ts escape='htmlattribute'}Pay Now{/ts}">
</form>
<script type="text/javascript">
document.getElementById("form").submit();
</script>
