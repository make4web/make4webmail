/*! Make4WebMail — core helpers (jQuery) */
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
  /**
   * The version of the app this page runs. An app left open (for days on a phone, back from
   * the background without reloading) keeps its old code: when the server answers with another
   * version, the page reloads itself where nothing is being written, else offers to.
   */
  M4W.version = $('meta[name="m4w-version"]').attr('content') || '';
  var newer = '';
  function safeToReload() {
    var page = location.pathname.slice(BASE.length).replace(/^\/|\/$/g, '');
    return !$('.m4w-compose, .m4w-editor-overlay').length && ['', 'mail', 'calendar', 'settings', 'files'].indexOf(page) !== -1
      && !$('input:focus, textarea:focus, [contenteditable]:focus').length;
  }
  function reloadNow() {
    if (navigator.serviceWorker && navigator.serviceWorker.getRegistration) {
      navigator.serviceWorker.getRegistration().then(function (r) { return r && r.update(); }).catch(function () {}).then(function () { location.reload(); });
    } else {
      location.reload();
    }
  }
  M4W.updateNow = reloadNow;
  M4W.newVersion = function (v) {
    if (!v || !M4W.version || v === M4W.version || newer === v) return;
    newer = v;
    // Never in a loop (a page kept somewhere on the way, served again after the reload).
    var tried = null;
    try { tried = JSON.parse(sessionStorage.getItem('m4w.reloadedFor') || 'null'); } catch (e) { /* private browsing */ }
    var again = tried && tried.v === v && Date.now() - tried.at < 600000;
    try { sessionStorage.setItem('m4w.reloadedFor', JSON.stringify({ v: v, at: again ? tried.at : Date.now() })); } catch (e) { /* private browsing */ }
    if (!again && safeToReload()) { reloadNow(); return; }
    if (M4W.toast) M4W.toast(M4W.t('new_version', { version: v }), 'info', { duration: 600000, action: { label: M4W.t('reload'), fn: reloadNow } });
    // Or when leaving the page in the background, if it is safe by then.
    document.addEventListener('visibilitychange', function () { if (document.hidden && safeToReload()) reloadNow(); });
  };
  $(document).ajaxComplete(function (e, xhr) {
    try { M4W.newVersion(xhr && xhr.getResponseHeader && xhr.getResponseHeader('X-M4W-Version')); } catch (err) { /* ignore */ }
  });

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
  var PALETTE = ['#1d4ed8', '#6d28d9', '#be185d', '#b91c1c', '#c2410c', '#a16207', '#15803d', '#0f766e', '#0e7490', '#4338ca'];
  M4W.color = function (seed) {
    seed = String(seed || '').toLowerCase();
    var h = 0;
    for (var i = 0; i < seed.length; i++) { h = ((h << 5) - h + seed.charCodeAt(i)) | 0; }
    return PALETTE[Math.abs(h) % PALETTE.length];
  };
  /**
   * Initials in a coloured circle, or the sender's logo (BIMI) when the server vouched for it:
   * served by our server, never fetched from the sender. Back to initials if it does not load.
   */
  M4W.avatar = function (name, email, cls, logo, verified) {
    var initials = M4W.esc(M4W.initials(name || email)), bg = M4W.color(email || name);
    if (logo && /^[a-z0-9.-]{1,190}$/.test(logo)) {
      var tip = M4W.t(verified ? 'bimi_vmc' : 'bimi_logo', { domain: logo });
      return '<span class="m4w-avatar has-logo ' + (cls || '') + (verified ? ' is-verified' : '') + '" data-bg="' + bg + '" data-initials="' + initials + '" title="' + M4W.esc(tip) + '">'
        + '<img src="' + M4W.esc(M4W.url('api/bimi/' + logo)) + '" alt="" loading="lazy" decoding="async"></span>';
    }
    return '<span class="m4w-avatar ' + (cls || '') + '" style="background:' + bg + '" aria-hidden="true">' + initials + '</span>';
  };
  document.addEventListener('error', function (e) {
    var img = e.target, box = img && img.parentNode;
    if (img.tagName === 'IMG' && box && box.classList && box.classList.contains('has-logo')) {
      box.classList.remove('has-logo', 'is-verified');
      box.style.background = box.getAttribute('data-bg');
      box.removeAttribute('title');
      box.setAttribute('aria-hidden', 'true');
      box.textContent = box.getAttribute('data-initials');
    }
  }, true);
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
    var off = M4W.Offline;
    // Without a connection: what the device kept answers, work done is queued (offline.js).
    if (off && off.handles && off.handles(method, path, opts)) return off.request(method, path, data, opts);
    var isForm = data instanceof FormData;
    if (!opts.silent) bar(true);
    var headers = { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' };
    if (opts.offlineStamp) headers['X-M4W-Offline'] = JSON.stringify(opts.offlineStamp);
    var out = $.Deferred();
    var xhr = $.ajax({
      url: M4W.url(path),
      method: method,
      data: method === 'GET' ? data : (isForm ? data : JSON.stringify(data || {})),
      contentType: method === 'GET' ? undefined : (isForm ? false : 'application/json'),
      processData: method === 'GET',
      dataType: 'json',
      headers: headers,
      xhr: opts.xhr
    });
    xhr.always(function () { if (!opts.silent) bar(false); }).then(function (res) {
      if (res && res.ok === false) return $.Deferred().reject({ responseJSON: res, status: 200 }).promise();
      return res;
    }).done(function (res) {
      if (off && off.capture && method === 'GET') off.capture(path, data, res);
      out.resolve(res);
    }).fail(function (x) {
      var r = x && x.responseJSON;
      // The connection is gone: the offline mode takes the request over.
      if (x && x.status === 0 && x.statusText !== 'abort' && !isForm && !opts.noOffline && off && off.fallback && off.fallback(method, path)) {
        off.goneOffline();
        off.request(method, path, data, opts).then(out.resolve, out.reject);
        return;
      }
      if (r && r.login) {
        // Session over: what the device keeps becomes unreadable before going to the sign-in page.
        $.when(off && off.lock ? off.lock() : null).always(function () { window.location.href = M4W.url('login'); });
        out.reject(x);
        return;
      }
      if (r && r.too_big) { M4W.tooBig(r); out.reject(x); return; }
      if (!opts.quiet && x && x.statusText !== 'abort') M4W.toast((r && r.error) || M4W.t('error_network'), 'danger');
      out.reject(x);
    });
    var p = out.promise();
    p.abort = function () { xhr.abort(); };
    return p;
  };
  M4W.get = function (p, d, o) { return M4W.api('GET', p, d, o); };

  // ---- file too big for e-mail: no public link, a file-transfer service instead
  M4W.tooBig = function (info) {
    info = info || {};
    var services = [];
    try { services = JSON.parse(($('#m4w-transfer').text() || '[]')); } catch (e) { services = []; }
    $('#m4w-toobig').remove();
    var what = info.name ? M4W.t('toobig_file', { name: info.name, size: M4W.bytes(info.size || 0), mb: info.max_mb || '' }) : M4W.t('toobig_total', { mb: info.max_mb || '' });
    var list = services.map(function (s) {
      return '<a class="btn btn-light m4w-transfer-btn" target="_blank" rel="noopener noreferrer" href="' + M4W.esc(s.url) + '"><i class="bi bi-box-arrow-up-right me-2"></i>' + M4W.esc(s.name) + '</a>';
    }).join('');
    var $m = $('<div class="modal fade" id="m4w-toobig" tabindex="-1" aria-labelledby="m4w-toobig-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
      + '<div class="modal-header"><h2 class="modal-title fs-5" id="m4w-toobig-title"><i class="bi bi-cloud-arrow-up me-2 text-primary"></i>' + M4W.esc(M4W.t('toobig_title')) + '</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' + M4W.esc(M4W.t('close')) + '"></button></div>'
      + '<div class="modal-body"><p>' + M4W.esc(what) + '</p><p class="small text-muted">' + M4W.esc(M4W.t('toobig_how')) + '</p>'
      + (list ? '<div class="d-grid gap-2 m4w-transfer-list">' + list + '</div>' : '')
      // Back from the service with its link: pasted here, added to the message being written.
      + (list && M4W.Compose && M4W.Compose.current() ? '<label class="form-label small mt-3 mb-1" for="m4w-toobig-link">' + M4W.esc(M4W.t('toobig_paste')) + '</label>'
        + '<div class="input-group"><input class="form-control" type="url" id="m4w-toobig-link" placeholder="https://…" inputmode="url"><button class="btn btn-primary" type="button" data-toobig-add>' + M4W.esc(M4W.t('toobig_add')) + '</button></div>'
        + '<div class="small text-danger mt-1 d-none" data-toobig-bad>' + M4W.esc(M4W.t('toobig_bad_link')) + '</div>' : '')
      + (info.file_id ? '<a class="btn btn-soft w-100 mt-3" href="' + M4W.esc(M4W.url('files/file/' + info.file_id)) + '"><i class="bi bi-download me-2"></i>' + M4W.esc(M4W.t('toobig_download')) + '</a>' : '')
      + '<p class="small text-muted mt-3 mb-0"><i class="bi bi-shield-lock me-1"></i>' + M4W.esc(M4W.t('toobig_internal')) + '</p></div>'
      + '<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">' + M4W.esc(M4W.t('close')) + '</button></div>'
      + '</div></div></div>').appendTo(document.body).on('hidden.bs.modal', function () { $m.remove(); });
    var add = function () {
      var url = String($m.find('#m4w-toobig-link').val() || '').trim(), host = '';
      try { var u = new URL(url); host = u.protocol === 'https:' ? u.hostname.replace(/^www\./, '') : ''; } catch (e) { host = ''; }
      $m.find('[data-toobig-bad]').toggleClass('d-none', !!host);
      var c = M4W.Compose.current();
      if (!host || !c || !c.editor) return;
      // The service it comes from, by its address (else its domain).
      var via = services.filter(function (s) { try { return new URL(s.url).hostname.replace(/^www\./, '').split('.').slice(-2).join('.') === host.split('.').slice(-2).join('.'); } catch (e) { return false; } })[0];
      c.editor.insertBlock('<p>&#128206; ' + (info.name ? '<strong>' + M4W.esc(info.name) + '</strong>' + (info.size ? ' (' + M4W.esc(M4W.bytes(info.size)) + ')' : '') + ' — ' : '')
        + '<a href="' + M4W.esc(url) + '">' + M4W.esc(M4W.t('toobig_link_label')) + '</a> <span style="color:#6b7280">(' + M4W.esc(via ? via.name : host) + ')</span></p>');
      if (c.touch) c.touch();
      bootstrap.Modal.getOrCreateInstance($m[0]).hide();
      M4W.toast(M4W.t('toobig_added'), 'success');
    };
    $m.on('click', '[data-toobig-add]', add).on('keydown', '#m4w-toobig-link', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(); } });
    bootstrap.Modal.getOrCreateInstance($m[0]).show();
  };
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

  // ------------------------------------------------------------ document editors over the page
  // Spreadsheet, Word and office editors open over the current page (same tab); the
  // browser's Back button or the editor's own "Back" closes them.
  M4W.openEditor = function (src, title, onClose) {
    $('.m4w-editor-overlay').remove();
    var $o = $('<div class="m4w-editor-overlay" role="dialog" aria-modal="true"></div>').attr('aria-label', title || '')
      .append($('<iframe allow="clipboard-read; clipboard-write; fullscreen"></iframe>').attr({ src: src, title: title || '' }))
      .appendTo(document.body);
    $('body').addClass('m4w-editor-open');
    var closed = false;
    try { history.pushState({ m4wEditor: 1 }, '', window.location.href); } catch (e) { /* ignore */ }
    function close() {
      if (closed) return;
      closed = true;
      window.removeEventListener('message', onMsg);
      window.removeEventListener('popstate', onPop);
      // Unload first, so the editor sends what is pending and releases the document.
      $o.find('iframe').attr('src', 'about:blank');
      setTimeout(function () { $o.remove(); $('body').removeClass('m4w-editor-open'); if (onClose) onClose(); }, 250);
    }
    function onMsg(e) {
      if (e.origin !== window.location.origin || !e.data || e.data.m4w !== 'editor-close') return;
      if (history.state && history.state.m4wEditor) history.back(); else close();
    }
    function onPop() { close(); }
    window.addEventListener('message', onMsg);
    window.addEventListener('popstate', onPop);
    $o.find('iframe').trigger('focus');
    return { close: close };
  };
  // Inside such an editor: its "Back" button closes the panel instead of loading the list.
  if (window.parent !== window) {
    $(document).on('click', '[data-editor-back]', function (e) {
      try {
        if (window.parent.location.origin !== window.location.origin) return;
      } catch (x) { return; }
      e.preventDefault();
      window.parent.postMessage({ m4w: 'editor-close' }, window.location.origin);
    });
  }

  // ------------------------------------------------------------ zoomable picture
  // Wheel (or pinch on a trackpad) zooms at the pointer, drag moves, double-click resets.
  M4W.zoomable = function (img) {
    var $img = $(img), $box = $img.parent(), z = 1, x = 0, y = 0, drag = null;
    $box.addClass('m4w-zoom-box');
    function apply() {
      $img.css('transform', 'translate(' + x + 'px,' + y + 'px) scale(' + z + ')').toggleClass('is-zoomed', z > 1);
      $box.attr('data-zoom-level', z > 1 ? Math.round(z * 100) + ' %' : '');
    }
    function reset() { z = 1; x = 0; y = 0; apply(); }
    $box.off('.m4wzoom').on('wheel.m4wzoom', function (e) {
      // The same box may later show something else (a document): let it scroll again.
      if (!document.body.contains(img)) { $box.off('.m4wzoom').removeClass('m4w-zoom-box').removeAttr('data-zoom-level'); return; }
      var ev = e.originalEvent;
      e.preventDefault();
      var nz = Math.min(8, Math.max(1, z * (ev.deltaY < 0 ? 1.15 : 1 / 1.15)));
      if (nz === z) return;
      // Keep the point under the pointer in place (from the layout box, not the animated one).
      var br = $box[0].getBoundingClientRect(), off = { left: img.offsetLeft + $box[0].clientLeft, top: img.offsetTop + $box[0].clientTop };
      var px = ev.clientX - (br.left + off.left + img.offsetWidth / 2 + x), py = ev.clientY - (br.top + off.top + img.offsetHeight / 2 + y);
      x -= px * (nz / z - 1); y -= py * (nz / z - 1);
      z = nz;
      if (z === 1) { x = 0; y = 0; }
      apply();
    }).on('dblclick.m4wzoom', function () { if (z > 1) reset(); else { z = 2; apply(); } })
      .on('pointerdown.m4wzoom', function (e) { if (z <= 1) return; e.preventDefault(); drag = { sx: e.clientX - x, sy: e.clientY - y }; this.setPointerCapture && this.setPointerCapture(e.originalEvent.pointerId); })
      .on('pointermove.m4wzoom', function (e) { if (!drag) return; x = e.clientX - drag.sx; y = e.clientY - drag.sy; apply(); })
      .on('pointerup.m4wzoom pointercancel.m4wzoom', function () { drag = null; });
    $img.attr('draggable', 'false');
    reset();
    return { reset: reset };
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
    // Accessibility: tie every .form-label to its control, name unlabeled checkboxes.
    var uid = 0;
    $('label.form-label:not([for])').each(function () {
      var $c = $(this).parent().find('input:not([type=hidden]), select, textarea').first();
      if (!$c.length) $c = $(this).next().find('input:not([type=hidden]), select, textarea').first();
      if (!$c.length) return;
      if (!$c.attr('id')) $c.attr('id', 'm4w-f' + (++uid));
      $(this).attr('for', $c.attr('id'));
    });
    $('input[type=checkbox]:not([aria-label]):not([id])').each(function () { $(this).attr('aria-label', M4W.t('select')); });
    $('.m4w-setting-row').each(function () {
      var label = $(this).children('.label').first().clone().children().remove().end().text().trim();
      if (!label) return;
      $(this).find('select:not([aria-label]), input:not([type=hidden]):not([aria-label]):not([id])').each(function () { if (!$('label[for="' + this.id + '"]').length) $(this).attr('aria-label', label); });
    });

    // Modals must live directly under <body> (animated containers create stacking contexts).
    $('.modal').appendTo(document.body);
    if (window.AOS) { AOS.init({ once: true, duration: 450, easing: 'ease-out-cubic', offset: 10 }); }
    $('[data-bs-toggle="tooltip"]').each(function () { bootstrap.Tooltip.getOrCreateInstance(this); });

    // Server flashes on pages without a flash area (mail shell) become toasts.
    $('[data-flash-toast]').each(function () { M4W.toast($(this).text(), $(this).data('flash-toast')); $(this).remove(); });

    // Open a delegated mailbox: reason dialog (required for administrators by default).
    $(document).on('click', '[data-open-mailbox]', function (e) {
      e.preventDefault();
      var $b = $(this), $m = $('#m4w-open-mailbox'), need = String($b.data('reason')) === '1';
      $m.find('[name=user_id]').val($b.data('open-mailbox'));
      $m.find('[data-mb-name]').text($b.data('name'));
      $m.find('[data-mb-email]').text($b.data('email'));
      $m.find('[data-mb-avatar]').text(M4W.initials($b.data('name') || $b.data('email'))).css('background', M4W.color($b.data('email')));
      $m.find('[data-mb-required]').toggle(need);
      $m.find('[name=reason]').prop('required', need).val('');
      $m.one('shown.bs.modal', function () { $m.find('[name=reason]').trigger('focus'); });
      bootstrap.Modal.getOrCreateInstance($m[0]).show();
    });

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
      e.stopImmediatePropagation();
      // form.submit() drops the clicked button: carry its name and value over.
      var by = e.originalEvent && e.originalEvent.submitter;
      M4W.confirm($(f).data('confirm'), $(f).data('confirm-text') || '', $(f).data('confirm-ok')).done(function () {
        f._confirmed = true;
        if (by && by.name) $('<input type="hidden">').attr('name', by.name).val(by.value).appendTo(f);
        $(f).trigger('submit'); f.submit();
      });
    });
    // Busy state on submit
    $(document).on('submit', 'form:not([data-no-busy])', function () {
      var $b = $(this).find('button[type=submit], button:not([type])').last();
      if ($b.length && !$b.find('.spinner-border').length) $b.prepend('<span class="spinner-border spinner-border-sm me-2"></span>');
    });

    // Installer: database driver fields
    var syncDb = function () { $('.m4w-mysql').toggle($('input[name=db_driver]:checked').val() === 'mysql'); };
    $(document).on('change', 'input[name=db_driver]', syncDb); syncDb();

    // Quick settings
    // Display size: applied at once, remembered for the account (every device).
    $(document).on('change', 'input[name=qs_zoom], input[name=zoom]', function () {
      var z = parseInt(this.value, 10) || 100;
      document.documentElement.setAttribute('data-zoom', String(z));
      $(window).trigger('resize');
      if (this.name === 'qs_zoom') M4W.savePrefs({ zoom: z });
    });
    $(document).on('change', 'input[name=qs_theme]', function () {
      var v = this.value; if (window.M4W_THEME) M4W_THEME.set(v); M4W.savePrefs({ theme: v });
    });

    $('#m4w-search').on('input', function () { $('[data-action=clear-search]').toggleClass('d-none', !this.value); }).trigger('input');
    // Search, from any page. The form holds the advanced fields too, so browsers do not submit it
    // on Enter by themselves (no submit button): Enter is handled here. On the mail page the
    // results show in place (M4W.searchHere), elsewhere the mail page opens with them.
    function runSearch() {
      var q = String($('#m4w-search').val() || '').trim();
      if (M4W.searchHere) { M4W.searchHere(q); return; }
      if (q) location.href = M4W.url('mail', { q: q });
    }
    $('#m4w-search-form').on('submit', function (e) { e.preventDefault(); runSearch(); });
    $(document).on('keydown', '#m4w-search', function (e) {
      if (e.key === 'Enter' && !e.originalEvent.isComposing) { e.preventDefault(); runSearch(); }
    });
    // Advanced search: the fields become operators in the search box (from:, subject:, has:…).
    $(document).on('click', '[data-action=adv-search]', function () {
      var $a = $('#m4w-adv-search'), parts = [];
      var q = function (n) { return String($a.find('[name=' + n + ']').val() || '').trim(); };
      var quote = function (v) { return /\s/.test(v) ? '"' + v.replace(/"/g, '') + '"' : v; };
      if (q('adv_from')) parts.push('from:' + quote(q('adv_from')));
      if (q('adv_to')) parts.push('to:' + quote(q('adv_to')));
      if (q('adv_subject')) parts.push('subject:' + quote(q('adv_subject')));
      if (q('adv_after')) parts.push('after:' + q('adv_after'));
      if (q('adv_before')) parts.push('before:' + q('adv_before'));
      if ($a.find('[name=adv_att]').is(':checked')) parts.push('has:attachment');
      if ($a.find('[name=adv_unread]').is(':checked')) parts.push('is:unread');
      if ($a.find('[name=adv_flag]').is(':checked')) parts.push('is:starred');
      if (q('adv_words')) parts.push(q('adv_words'));
      $('#m4w-search').val(parts.join(' ')).trigger('input');
      var t = $a.prev('[data-bs-toggle]')[0];
      if (t && window.bootstrap) bootstrap.Dropdown.getOrCreateInstance(t).hide();
      runSearch();
    });
    $(document).on('keydown', '#m4w-adv-search input', function (e) {
      if (e.key === 'Enter' && !e.originalEvent.isComposing) { e.preventDefault(); $('[data-action=adv-search]').trigger('click'); }
    });

    // While the app is open (even in a background tab), the session is not idle: it ends only
    // after the idle time with the app closed, or at its maximum length (Admin › Security).
    var keep = +($('meta[name="m4w-keepalive"]').attr('content') || 0);
    if (keep > 0) {
      var lastPing = Date.now(), ping = function () { lastPing = Date.now(); M4W.get('api/ping', null, { silent: true, quiet: true }); };
      setInterval(ping, keep * 1000);
      // Back on an app that slept (phone): signed out meanwhile → straight to the sign-in page.
      document.addEventListener('visibilitychange', function () { if (!document.hidden && Date.now() - lastPing > 60000) ping(); });
    }

    // Password policy of the administrator, checked as one types: the rules show under the field,
    // and the form cannot be sent with a password the server would refuse.
    var pol = ($('meta[name="m4w-pw-policy"]').attr('content') || '10,3').split(',').map(Number);
    function pwCheck(input) {
      var v = input.value, $in = $(input), min = pol[0], need = pol[1];
      var classes = (/[a-z]/.test(v) ? 1 : 0) + (/[A-Z]/.test(v) ? 1 : 0) + (/\d/.test(v) ? 1 : 0) + (/[^a-zA-Z\d]/.test(v) ? 1 : 0);
      var okLen = v.length >= min, okCls = classes >= need, empty = v === '';
      var $rules = $in.data('pwRules');
      if (!$rules) {
        $rules = $('<ul class="m4w-pw-rules" aria-live="polite"><li data-r="len"></li><li data-r="cls"></li></ul>');
        var $after = $in.closest('.input-group').length ? $in.closest('.input-group') : $in;
        var $bar = $after.nextAll('.progress').first();
        $rules.insertAfter($bar.length ? $bar : $after);
        $in.data('pwRules', $rules);
      }
      var mark = function (ok) { return '<i class="bi bi-' + (ok ? 'check-circle-fill' : 'circle') + '" aria-hidden="true"></i>'; };
      $rules.toggleClass('d-none', empty && !input.required).find('[data-r=len]').toggleClass('ok', okLen).html(mark(okLen) + M4W.esc(M4W.t('pw_rule_len', { n: min }) + ' (' + v.length + ')'));
      $rules.find('[data-r=cls]').toggleClass('ok', okCls).html(mark(okCls) + M4W.esc(M4W.t('pw_rule_classes', { n: need }) + ' (' + Math.min(classes, 4) + '/' + need + ')'));
      var msg = empty ? '' : (!okLen ? M4W.t('pw_rule_len', { n: min }) : (!okCls ? M4W.t('pw_rule_classes', { n: need }) : ''));
      input.setCustomValidity(msg);
      $in.toggleClass('is-invalid', !empty && !!msg && $in.data('pwTouched') === true).toggleClass('is-valid', !empty && !msg);
    }
    $('input[data-strength]').each(function () { pwCheck(this); });
    $(document).on('input change', 'input[data-strength]', function () { pwCheck(this); });
    $(document).on('blur', 'input[data-strength]', function () { $(this).data('pwTouched', true); pwCheck(this); });
    $(document).on('invalid', 'input[data-strength]', function () { $(this).data('pwTouched', true).addClass('is-invalid'); });

    // A form refused by the server comes back as it was typed (passwords aside): nothing to retype.
    var keepKey = function (f) { return 'm4w-form:' + ($(f).attr('action') || location.pathname) + ':' + ($(f).find('[name=id]').val() || ''); };
    var keepSkip = function (el) { return !el.name || el.type === 'password' || el.type === 'file' || el.type === 'hidden' || /password|_csrf|csrf/i.test(el.name) || el.disabled; };
    $(document).on('submit', 'form[data-keep-input]', function () {
      var vals = [];
      $.each(this.elements, function (_, el) {
        if (keepSkip(el) || el.tagName === 'BUTTON') return;
        vals.push(el.type === 'checkbox' || el.type === 'radio' ? { n: el.name, v: el.value, c: el.checked } : { n: el.name, v: $(el).val() });
      });
      try { sessionStorage.setItem(keepKey(this), JSON.stringify({ t: Date.now(), vals: vals })); } catch (e) { /* private mode */ }
    });
    $('form[data-keep-input]').each(function () {
      var form = this, key = keepKey(form), saved = null;
      try { saved = JSON.parse(sessionStorage.getItem(key) || 'null'); sessionStorage.removeItem(key); } catch (e) { saved = null; }
      // Only after a refusal (an error shown), and only just after sending.
      if (!saved || Date.now() - saved.t > 10 * 60000 || !$('.m4w-flash .alert-danger, .m4w-flash .alert-warning').length) return;
      saved.vals.forEach(function (s) {
        $(form.elements).filter(function () { return this.name === s.n && !keepSkip(this); }).each(function () {
          if (this.type === 'checkbox' || this.type === 'radio') { if (this.value === s.v) this.checked = s.c; }
          else { $(this).val(s.v); }
        });
      });
      $(form.elements).trigger('change');
      $(form).find('[data-strength]').first().trigger('focus');
    });

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
