/*! Make4Web Mail — login / 2FA pages */
(function ($) {
  'use strict';
  $(function () {
    var $login = $('form[data-m4w-login]');
    $login.on('submit', function (e) {
      if (!this.checkValidity()) { e.preventDefault(); e.stopPropagation(); $(this).addClass('was-validated'); return; }
      $(this).find('button[type=submit]').prop('disabled', true).find('.spinner-border').removeClass('d-none');
    });
    $('#password').on('keyup keydown', function (e) {
      if (e.originalEvent && e.originalEvent.getModifierState) $('[data-capslock]').toggleClass('d-none', !e.originalEvent.getModifierState('CapsLock'));
    });

    var $otp = $('[data-otp-inputs] input');
    function sync() {
      var code = $otp.map(function () { return this.value; }).get().join('');
      $('#otp-code').val(code);
      return code;
    }
    $otp.on('input', function () {
      this.value = this.value.replace(/\D/g, '').slice(0, 1);
      if (this.value) $(this).next('input').trigger('focus');
      if (sync().length === 6) $(this).closest('form').trigger('submit');
    }).on('keydown', function (e) {
      if (e.key === 'Backspace' && !this.value) $(this).prev('input').trigger('focus');
    }).on('paste', function (e) {
      var t = (e.originalEvent.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
      if (!t) return;
      e.preventDefault();
      $otp.each(function (i) { this.value = t.charAt(i) || ''; });
      if (sync().length === 6) $(this).closest('form').trigger('submit');
    });
    $('form[data-m4w-otp]').on('submit', function () { sync(); });
  });
})(jQuery);
