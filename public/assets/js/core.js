/*! Make4Web Mail — core helpers (jQuery) */
(function ($, window, document) {
  'use strict';

  var I18N = {};
  try { I18N = JSON.parse(document.getElementById('m4w-i18n').textContent || '{}'); } catch (e) { I18N = {}; }
  var BASE = ($('meta[name="base-url"]').attr('content') || '/').replace(/\/$/, '');
  var CSRF = $('meta[name="csrf-token"]').attr('content') || '';

  var M4W = window.M4W = window.M4W || {};

  M4W.t = function (key, vars) {
    var s = I18N[key] !== undefined ? I18N[key] : key;
    if (vars) { Object.keys(vars).sort(function (a, b) { return b.length - a.length; }).forEach(function (k) { s = s.split(':' + k).join(vars[k]); }); }
    return s;
  };
  M4W.url = function (path, query) {
    var u = BASE + '/' + String(path || '').replace(/^\//, '');
    if (query) { var q = $.param(query); if (q) u += (u.indexOf('?') === -1 ? '?' : '&') + q; }
    return u;
  };
  M4W.csrf = function (v) { if (v) { CSRF = v; $('meta[name="csrf-token"]').attr('content', v); $('input[name=_csrf]').val(v); } return CSRF; };

  M4W.esc = function (s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"'`=\/]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;', '=': '&#61;', '/': '&#47;' }[c];
    });
  };
  M4W.initials = function (name) {
    name = String(name || '?').replace(/[<"].*$/, '').trim();
    var p = name.split(/[\s._@-]+/).filter(Boolean);
    var i = (p[0] || '?').charAt(0);
    if (p.length > 1) i += p[1].charAt(0);
    return i.toUpperCase();
  };
  var PALETTE = ['#2563eb', '#7c3aed', '#db2777', '#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#0d9488', '#0891b2', '#4f46e5'];
  M4W.color = function (seed) {
    seed = String(seed || '').toLowerCase();
    var h = 0;
    for (var i = 0; i < seed.length; i++) { h = ((h << 5) - h + seed.charCodeAt(i)) | 0; }
    return PALETTE[Math.abs(h) % PALETTE.length];
  };
  M4W.avatar = function (name, email, cls) {
    return '<span class="m4w-avatar ' + (cls || '') + '" style="background:' + M4W.color(email || name) + '" aria-hidden="true">' + M4W.esc(M4W.initials(name || email)) + '</span>';
  };
  M4W.bytes = function (n) {
    var u = M4W.t('bytes_units').split(','), i = 0; n = +n || 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n : n.toFixed(n < 10 ? 1 : 0)) + ' ' + u[i];
  };
  var LANG = document.documentElement.lang || 'fr';
  M4W.date = function (ts, full) {
    if (!ts) return '';
    var d = new Date(ts * 1000), now = new Date();
    if (full) {
      return d.toLocaleString(LANG, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString(LANG, { hour: '2-digit', minute: '2-digit' });
    var y = new Date(now); y.setDate(now.getDate() - 1);
    if (d.toDateString() === y.toDateString()) return M4W.t('yesterday');
    if ((now - d) < 6 * 86400000) return d.toLocaleDateString(LANG, { weekday: 'short' });
    if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString(LANG, { day: 'numeric', month: 'short' });
    return d.toLocaleDateString(LANG, { day: '2-digit', month: '2-digit', year: 'numeric' });
  };
  M4W.relative = function (ts) {
    var diff = Math.round(Date.now() / 1000 - ts);
    if (diff < 60) return M4W.t('just_now');
    if (diff < 3600) return M4W.t('min_ago', { n: Math.floor(diff / 60) });
    if (diff < 86400) return M4W.t('hours_ago', { n: Math.floor(diff / 3600) });
    return M4W.date(ts);
  };
  M4W.dayGroup = function (ts) {
    var d = new Date(ts * 1000), now = new Date();
    var start = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime() / 1000;
    if (ts >= start) return M4W.t('today');
    if (ts >= start - 86400) return M4W.t('yesterday');
    if (ts >= start - 6 * 86400) return M4W.t('this_week');
    if (d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth()) return M4W.t('this_month');
    return d.toLocaleDateString(LANG, { month: 'long', year: 'numeric' });
  };

  // ---- loading bar
  var pending = 0, barTimer;
  function bar(on) {
    var $b = $('#m4w-loading');
    if (on) { pending++; $b.addClass('active').css('width', '30%'); clearTimeout(barTimer); barTimer = setTimeout(function () { if (pending) $b.css('width', '70%'); }, 400); }
    else { pending = Math.max(0, pending - 1); if (!pending) { $b.css('width', '100%'); setTimeout(function () { if (!pending) $b.removeClass('active').css('width', 0); }, 250); } }
  }

  // ---- API
  M4W.api = function (method, path, data, opts) {
    opts = opts || {};
    var isForm = data instanceof FormData;
    if (!opts.silent) bar(true);
    return $.ajax({
      url: M4W.url(path),
      method: method,
      data: method === 'GET' ? data : (isForm ? data : JSON.stringify(data || {})),
      contentType: method === 'GET' ? undefined : (isForm ? false : 'application/json'),
      processData: method === 'GET',
      dataType: 'json',
      headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
      xhr: opts.xhr
    }).always(function () { if (!opts.silent) bar(false); }).then(function (res) {
      if (res && res.ok === false) return $.Deferred().reject({ responseJSON: res }).promise();
      return res;
    }).fail(function (xhr) {
      var r = xhr && xhr.responseJSON;
      if (r && r.login) { window.location.href = M4W.url('login'); return; }
      if (!opts.quiet && xhr && xhr.statusText !== 'abort') M4W.toast((r && r.error) || M4W.t('error_network'), 'danger');
    });
  };
  M4W.get = function (p, d, o) { return M4W.api('GET', p, d, o); };
  M4W.post = function (p, d, o) { return M4W.api('POST', p, d, o); };

  // ---- toasts
  M4W.toast = function (msg, type, opts) {
    opts = opts || {};
    var icon = { success: 'check-circle-fill', danger: 'exclamation-octagon-fill', warning: 'exclamation-triangle-fill', info: 'info-circle-fill' }[type || 'info'] || 'info-circle-fill';
    var color = { success: '#4ade80', danger: '#f87171', warning: '#fbbf24', info: '#93c5fd' }[type || 'info'];
    var $t = $('<div class="toast show" role="status"></div>');
    $t.append('<i class="bi bi-' + icon + '" style="color:' + color + '"></i>');
    $t.append($('<div class="flex-grow-1"></div>').text(msg));
    if (opts.action) {
      $('<button class="btn btn-link btn-sm" type="button"></button>').text(opts.action.label).on('click', function () { opts.action.fn(); close(); }).appendTo($t);
    }
    $('<button type="button" class="btn-close btn-close-white btn-sm ms-1" aria-label="close"></button>').on('click', close).appendTo($t);
    $('#m4w-toasts').append($t);
    var timer = setTimeout(close, opts.duration || (opts.action ? 7000 : 4000));
    function close() { clearTimeout(timer); $t.fadeOut(150, function () { $t.remove(); }); if (opts.onClose) opts.onClose(); }
    return { close: close, el: $t };
  };

  M4W.confirm = function (title, text, okLabel) {
    var d = $.Deferred(), $m = $('#m4w-confirm');
    if (!$m.length) { d[window.confirm(title + '\n' + (text || '')) ? 'resolve' : 'reject'](); return d.promise(); }
    $m.find('[data-title]').text(title);
    $m.find('[data-text]').text(text || '');
    $m.find('[data-ok]').text(okLabel || M4W.t('confirm')).off('click').on('click', function () { d.resolve(); bootstrap.Modal.getOrCreateInstance($m[0]).hide(); });
    $m.off('hidden.bs.modal').on('hidden.bs.modal', function () { if (d.state() === 'pending') d.reject(); });
    bootstrap.Modal.getOrCreateInstance($m[0]).show();
    return d.promise();
  };

  M4W.debounce = function (fn, ms) { var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); }; };

  M4W.fileIcon = function (name, mime) {
    var ext = String(name || '').split('.').pop().toLowerCase();
    mime = mime || '';
    if (mime.indexOf('image/') === 0) return ['img', 'file-earmark-image'];
    if (ext === 'pdf' || mime === 'application/pdf') return ['pdf', 'file-earmark-pdf'];
    if (/^(docx?|odt|rtf|pages)$/.test(ext)) return ['doc', 'file-earmark-word'];
    if (/^(xlsx?|ods|csv|numbers)$/.test(ext)) return ['xls', 'file-earmark-spreadsheet'];
    if (/^(pptx?|odp|key)$/.test(ext)) return ['doc', 'file-earmark-slides'];
    if (/^(zip|rar|7z|gz|tar|bz2)$/.test(ext)) return ['zip', 'file-earmark-zip'];
    if (/^(mp3|wav|ogg|m4a|flac)$/.test(ext)) return ['', 'file-earmark-music'];
    if (/^(mp4|mov|avi|mkv|webm)$/.test(ext)) return ['', 'file-earmark-play'];
    if (ext === 'eml' || mime === 'message/rfc822') return ['doc', 'envelope'];
    if (ext === 'ics' || mime === 'text/calendar') return ['', 'calendar-event'];
    if (/^(txt|log|md)$/.test(ext)) return ['', 'file-earmark-text'];
    return ['', 'file-earmark'];
  };

  M4W.savePrefs = function (prefs) { return M4W.post('api/prefs', { prefs: prefs }, { silent: true }); };

  // ---- global UI wiring
  $(function () {
    if (window.AOS) { AOS.init({ once: true, duration: 450, easing: 'ease-out-cubic', offset: 10 }); }
    $('[data-bs-toggle="tooltip"]').each(function () { bootstrap.Tooltip.getOrCreateInstance(this); });

    $(document).on('click', '[data-action="toggle-sidebar"]', function () { $('body').toggleClass('sidebar-open'); });
    $(document).on('click', function (e) {
      if ($('body').hasClass('sidebar-open') && !$(e.target).closest('.m4w-sidebar, [data-action="toggle-sidebar"]').length) $('body').removeClass('sidebar-open');
    });

    $(document).on('click', '[data-toggle-password]', function () {
      var $i = $($(this).data('toggle-password'));
      var show = $i.attr('type') === 'password';
      $i.attr('type', show ? 'text' : 'password');
      $(this).find('.bi').toggleClass('bi-eye bi-eye-slash');
    });

    // Forms needing confirmation
    $(document).on('submit', 'form[data-confirm]', function (e) {
      var f = this;
      if (f._confirmed) return;
      e.preventDefault();
      M4W.confirm($(f).data('confirm'), $(f).data('confirm-text') || '', $(f).data('confirm-ok')).done(function () { f._confirmed = true; $(f).trigger('submit'); f.submit(); });
    });
    // Busy state on submit
    $(document).on('submit', 'form:not([data-no-busy])', function () {
      var $b = $(this).find('button[type=submit], button:not([type])').last();
      if ($b.length && !$b.find('.spinner-border').length) $b.prepend('<span class="spinner-border spinner-border-sm me-2"></span>');
    });

    // Quick settings
    $(document).on('change', 'input[name=qs_theme]', function () {
      var v = this.value; if (window.M4W_THEME) M4W_THEME.set(v); M4W.savePrefs({ theme: v });
    });

    // Search outside mail page: navigate to /mail?q=
    $('#m4w-search').on('input', function () { $('[data-action=clear-search]').toggleClass('d-none', !this.value); }).trigger('input');

    // Password strength meters
    $(document).on('input', 'input[data-strength]', function () {
      var v = this.value, s = 0;
      if (v.length >= 10) s++; if (v.length >= 14) s++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^a-zA-Z0-9]/.test(v)) s++;
      var pct = Math.min(100, s * 20), cls = s <= 2 ? 'bg-danger' : (s <= 3 ? 'bg-warning' : 'bg-success');
      var $bar = $(this).closest('form').find('[data-strength-bar]');
      $bar.css('width', pct + '%').removeClass('bg-danger bg-warning bg-success').addClass(cls);
    });
  });
})(jQuery, window, document);
