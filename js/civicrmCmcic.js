(function ($, Drupal) {
  'use strict';

  if (Drupal && Drupal.AjaxCommands) {
    Drupal.AjaxCommands.prototype.cmcicRedirect = function (ajax, response, status) {
      var url = response.url;
      if (url) {
        try {
          if (window.top && window.top.location) {
            window.top.location.href = url;
            return;
          }
        } catch (e) {
          // Fallback if window.top is cross-origin restricted
        }
        window.location.href = url;
      }
    };
  }
})(jQuery, window.Drupal);
