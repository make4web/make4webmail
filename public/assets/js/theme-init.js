/* Applies the light/dark theme before first paint (CSP-friendly, no inline script). */
(function () {
  var s = document.currentScript, pref = (s && s.getAttribute('data-theme-pref')) || 'auto';
  try { var local = localStorage.getItem('m4w-theme'); if (local && !s.getAttribute('data-theme-pref')) pref = local; } catch (e) {}
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function apply() {
    var dark = pref === 'dark' || (pref === 'auto' && mq && mq.matches);
    document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
  }
  apply();
  if (mq && mq.addEventListener) mq.addEventListener('change', function () { if (pref === 'auto') apply(); });
  window.M4W_THEME = { set: function (v) { pref = v; apply(); }, get: function () { return pref; } };
})();
