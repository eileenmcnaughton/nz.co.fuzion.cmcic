{$url}
<form method="post"
name="CMCICFormulaire"
target="_top" action="{$url}">
{foreach from=$fields key=k item=field}
  <input type="hidden" name="{$k}" value="{$field}">
{/foreach}
<input type="submit" name="bouton" value="Paiement CB">
</form>
