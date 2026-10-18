/*! Make4WebMail — installable app and push notifications on this device */
(function ($, window, navigator) {
  'use strict';
  var M4W = window.M4W, t = M4W.t;
  var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;
  var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  var ios = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var reg = null, ready = $.Deferred();

  // ------------------------------------------------------- install invitation (phones and tablets)
  // In a mobile browser, the app is offered for the home screen: Android hands its install
  // prompt (beforeinstallprompt), iOS has none, so its two taps are explained. "Later" waits 30 days.
  var mobile = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches) && Math.min(screen.width, screen.height) < 1024;
  var inApp = /FBAN|FBAV|Instagram|Line\/|LinkedInApp|Twitter|MicroMessenger/i.test(navigator.userAgent); // in-app browsers cannot install
  var LATER_KEY = 'm4w.install.later', LATER_MS = 30 * 86400000, deferredPrompt = null;
  function later() { try { return Date.now() - (+window.localStorage.getItem(LATER_KEY) || 0) < LATER_MS; } catch (e) { return false; } }
  var installOffered = mobile && !standalone && !inApp && !later();
  function hideInstall() { $('#m4w-install').remove(); }
  function showInstall(kind) {
    if (!installOffered || $('#m4w-install').length) return;
    var name = $('meta[name=apple-mobile-web-app-title]').attr('content') || document.title;
    var icon = $('link[rel=apple-touch-icon]').attr('href') || '';
    var text = kind === 'ios'
      ? t('install_ios_html', { share: '<i class="bi bi-box-arrow-up" aria-label="' + M4W.esc(t('install_share')) + '"></i>' })
      : M4W.esc(t('install_text'));
    var $c = $('<div class="m4w-install" id="m4w-install" role="dialog" aria-live="polite"></div>').attr('aria-label', t('install_title', { name: name }))
      .append($('<img alt="" width="44" height="44">').attr('src', icon))
      .append($('<div class="m4w-install-text"></div>').append($('<strong></strong>').text(t('install_title', { name: name }))).append($('<span></span>').html(text)))
      .append($('<div class="m4w-install-actions"></div>')
        .append($('<button type="button" class="btn btn-light btn-sm" data-install-later></button>').text(t(kind === 'ios' ? 'install_ok' : 'install_later')))
        .append(kind === 'ios' ? '' : $('<button type="button" class="btn btn-primary btn-sm" data-install-go></button>').text(t('install_go'))));
    $('body').append($c);
  }
  $(document).on('click', '[data-install-later]', function () {
    try { window.localStorage.setItem(LATER_KEY, String(Date.now())); } catch (e) {}
    installOffered = false; hideInstall();
  });
  $(document).on('click', '[data-install-go]', function () {
    if (!deferredPrompt) { hideInstall(); return; }
    var p = deferredPrompt; deferredPrompt = null;
    p.prompt();
    p.userChoice.then(function (c) { hideInstall(); if (c && c.outcome !== 'accepted') { try { window.localStorage.setItem(LATER_KEY, String(Date.now())); } catch (e) {} } }, hideInstall);
  });
  window.addEventListener('beforeinstallprompt', function (e) {
    if (!installOffered) return; // the browser keeps its own menu entry
    e.preventDefault();
    deferredPrompt = e;
    setTimeout(function () { showInstall('prompt'); }, 1500);
  });
  window.addEventListener('appinstalled', function () { installOffered = false; hideInstall(); });
  if (installOffered && ios) {
    $(function () { setTimeout(function () { showInstall('ios'); }, 2500); });
  }

  function b64uToBytes(s) {
    var raw = window.atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  if ('serviceWorker' in navigator && window.isSecureContext) {
    // The version in its address: each new version of the app installs its service worker (the
    // part that shows the notifications), even when the file itself did not change.
    navigator.serviceWorker.register(M4W.url('sw.js') + (M4W.version ? '?v=' + encodeURIComponent(M4W.version) : ''), { scope: M4W.url(''), updateViaCache: 'none' }).then(function (r) {
      reg = r; ready.resolve(r);
    }, function () { ready.reject(); });
  } else {
    ready.reject();
  }

  function subscription() {
    return ready.then(function (r) { return r.pushManager.getSubscription(); });
  }

  /**
   * State of this device: unsupported | ios-install (iPhone outside the home screen) |
   * denied | on | off | disabled (turned off by the administrator).
   */
  function state() {
    var d = $.Deferred();
    if (!supported) { d.resolve(ios && !standalone ? 'ios-install' : 'unsupported'); return d.promise(); }
    if (Notification.permission === 'denied') { d.resolve('denied'); return d.promise(); }
    subscription().then(function (s) { d.resolve(s && Notification.permission === 'granted' ? 'on' : 'off'); }, function () { d.resolve('unsupported'); });
    return d.promise();
  }

  function enable() {
    var d = $.Deferred();
    M4W.get('api/push/key', null, { silent: true }).done(function (k) {
      if (!k.enabled || !k.key) { d.reject(t('push_disabled')); return; }
      Promise.resolve(Notification.requestPermission()).then(function (p) {
        if (p !== 'granted') throw new Error(t('push_denied'));
        return ready.promise();
      }).then(function (r) {
        return r.pushManager.getSubscription().then(function (old) {
          // A subscription made with another server key cannot be reused.
          if (old && old.options && old.options.applicationServerKey) {
            var a = new Uint8Array(old.options.applicationServerKey), b = b64uToBytes(k.key), same = a.length === b.length;
            for (var i = 0; same && i < a.length; i++) same = a[i] === b[i];
            if (same) return old;
            return old.unsubscribe().then(function () { return null; });
          }
          return old;
        }).then(function (s) {
          return s || r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(k.key) });
        });
      }).then(function (s) {
        M4W.post('api/push/subscribe', { subscription: s.toJSON() }).done(function () { d.resolve(); }).fail(function () { d.reject(''); });
      }).catch(function (e) { d.reject(e && e.message ? e.message : t('push_failed')); });
    }).fail(function () { d.reject(''); });
    return d.promise();
  }

  function disable() {
    var d = $.Deferred();
    subscription().then(function (s) {
      if (!s) { d.resolve(); return; }
      var endpoint = s.endpoint;
      s.unsubscribe().then(function () {
        M4W.post('api/push/unsubscribe', { endpoint: endpoint }, { quiet: true }).always(function () { d.resolve(); });
      }, function () { d.resolve(); });
    }, function () { d.resolve(); });
    return d.promise();
  }

  /** SHA-256 of this device's endpoint, to recognise it in the device list. */
  function currentHash() {
    return subscription().then(function (s) {
      if (!s || !window.crypto || !crypto.subtle) return '';
      return crypto.subtle.digest('SHA-256', new TextEncoder().encode(s.endpoint)).then(function (buf) {
        return Array.prototype.map.call(new Uint8Array(buf), function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
      });
    });
  }

  M4W.Push = { supported: supported, standalone: standalone, ios: ios, state: state, enable: enable, disable: disable, currentHash: currentHash, showInstall: showInstall };

  // ---------------------------------------------------------------- settings block
  $(function () {
    var $box = $('#m4w-push');
    if (!$box.length) return;
    function render() {
      state().done(function (s) {
        $box.attr('data-state', s);
        $box.find('[data-push-state]').addClass('d-none').filter('[data-push-state~="' + s + '"]').removeClass('d-none');
      });
      currentHash().then(function (h) {
        $box.find('[data-device]').each(function () {
          $(this).find('[data-this-device]').toggleClass('d-none', !h || $(this).data('hash') !== h);
        });
      }, function () {});
    }
    $box.on('click', '[data-push-enable]', function () {
      var $b = $(this).prop('disabled', true);
      enable().done(function () { M4W.toast(t('push_enabled'), 'success'); setTimeout(function () { location.reload(); }, 600); })
        .fail(function (msg) { if (msg) M4W.toast(msg, 'danger'); render(); })
        .always(function () { $b.prop('disabled', false); });
    }).on('click', '[data-push-disable]', function () {
      disable().done(function () { M4W.toast(t('push_off'), 'info'); setTimeout(function () { location.reload(); }, 600); });
    }).on('click', '[data-push-test-all]', function () {
      // Every device at once, one row each: the app's state there, and each step of the
      // notification (sent to its push service, received by the device, shown, opened).
      var $b = $(this).prop('disabled', true), $t = $box.find('[data-test-table]').removeClass('d-none');
      var secs = function (ms) { return ms === null || ms === undefined ? '' : (Math.max(0, ms) / 1000).toFixed(1).replace('.', ',') + ' s'; };
      var badge = { refused: 'danger', pending: 'warning', received: 'primary', shown: 'success', clicked: 'success' };
      var stateBadge = { off: 'secondary', background: 'warning', visible: 'primary', focus: 'success' };
      var draw = function (st) {
        var pending = 0;
        $t.find('tbody').html(st.devices.map(function (d) {
          if (d.status === 'pending') pending++;
          var status = d.status === 'pending' && st.age > 30000 ? 'late' : d.status;
          var state = d.state ? '<span class="badge badge-soft-' + stateBadge[d.state] + '">' + M4W.esc(t('push_st_' + d.state)) + '</span>'
            : '<span class="text-muted">' + M4W.esc(t('push_seen_' + (d.seen || 'quiet'))) + '</span>';
          var steps = [d.accepted ? t('push_step_sent', { ms: d.push_ms }) : t('push_step_refused')];
          if (d.received_ms !== null) steps.push(t('push_step_received') + ' ' + secs(d.received_ms));
          if (d.shown_ms !== null) steps.push(t('push_step_shown') + ' ' + secs(d.shown_ms));
          if (d.clicked_ms !== null) steps.push(t('push_step_clicked') + ' ' + secs(d.clicked_ms));
          return '<tr><td><i class="bi bi-' + M4W.esc(d.icon || 'display') + ' me-1 text-muted"></i>' + M4W.esc(d.label) + '</td><td>' + state + '</td>'
            + '<td><span class="badge badge-soft-' + (status === 'late' ? 'danger' : badge[d.status]) + '">' + M4W.esc(t('push_status_' + status)) + '</span>'
            + '<div class="text-muted">' + M4W.esc(steps.join(' · ')) + '</div></td>'
            + '<td class="text-end text-nowrap fw-semibold">' + (d.shown_ms !== null ? secs(d.shown_ms) : (d.received_ms !== null ? secs(d.received_ms) : '—')) + '</td></tr>';
        }).join(''));
        $t.find('[data-test-note]').text(pending ? (st.age > 30000 ? t('push_test_not_yet') : t('push_test_waiting')) : t('push_test_done'));
        return pending;
      };
      $t.find('tbody').html('<tr><td colspan="4" class="text-muted">' + M4W.esc(t('push_test_sending')) + '</td></tr>');
      M4W.post('api/push/test-all').done(function (r) {
        var tries = 0;
        (function again() {
          M4W.get('api/push/test-all/' + r.id, null, { silent: true, quiet: true }).done(function (st) {
            // Followed for a minute (a device asleep may answer late), then left as it is.
            if (draw(st) && ++tries < 60) setTimeout(again, 1000); else $b.prop('disabled', false);
          }).fail(function () { $b.prop('disabled', false); });
        })();
      }).fail(function () { $b.prop('disabled', false); $t.addClass('d-none'); });
    }).on('click', '[data-push-test]', function () {
      M4W.post('api/push/test').done(function (r) { M4W.toast(r.message, 'success'); });
    }).on('click', '[data-device-remove]', function () {
      var $row = $(this).closest('[data-device]');
      M4W.post('api/push/unsubscribe', { id: +$row.data('id') }).done(function () {
        $row.fadeOut(150, function () { $row.remove(); });
        if ($row.find('[data-this-device]:not(.d-none)').length) subscription().then(function (s) { if (s) s.unsubscribe(); }).then(render, render);
      });
    });
    render();
  });

  // ---------------------------------------------------------------- invitation in the mailbox
  $(function () {
    var $inv = $('#m4w-push-invite');
    if (!$inv.length) return;
    var key = 'm4w.push.invite';
    try { if (window.localStorage.getItem(key)) return; } catch (e) {}
    state().done(function (s) {
      if (s !== 'off' && s !== 'ios-install') return;
      if (s === 'ios-install' && installOffered) return; // the install card says it already
      $inv.find('[data-invite]').addClass('d-none').filter('[data-invite="' + (s === 'off' ? 'enable' : 'ios') + '"]').removeClass('d-none');
      $inv.removeClass('d-none');
    });
    function dismiss() { try { window.localStorage.setItem(key, '1'); } catch (e) {} $inv.addClass('d-none'); }
    $inv.on('click', '[data-invite-close]', dismiss).on('click', '[data-push-enable]', function () {
      enable().done(function () { M4W.toast(t('push_enabled'), 'success'); dismiss(); }).fail(function (msg) { if (msg) M4W.toast(msg, 'danger'); dismiss(); });
    });
  });

  // Settings: the version of the page and of the service worker of this device, and a button to
  // update both (the kept app files dropped, the service worker refreshed, the page reloaded).
  $(function () {
    var $v = $('[data-app-version]');
    if (!$v.length) return;
    var server = String($v.data('app-version')), answered = false;
    var line = function (sw) { $v.text(M4W.t('app_version_line', { server: server, page: M4W.version || '?', sw: sw })); };
    var ask = function () {
      if (!navigator.serviceWorker || !navigator.serviceWorker.controller || typeof MessageChannel === 'undefined') { line(M4W.t('sw_none')); return; }
      var ch = new MessageChannel();
      answered = false;
      ch.port1.onmessage = function (e) { answered = true; line((e.data && e.data.version) || '?'); };
      navigator.serviceWorker.controller.postMessage({ type: 'm4w-version' }, [ch.port2]);
      setTimeout(function () { if (!answered) line('?'); }, 2000);
    };
    ask();
    if (navigator.serviceWorker) navigator.serviceWorker.addEventListener('controllerchange', ask);
    $('[data-app-update]').on('click', function () {
      var $b = $(this).prop('disabled', true);
      var drop = window.caches ? caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return /^m4w-(static|pages)/.test(k); }).map(function (k) { return caches.delete(k); }));
      }) : Promise.resolve();
      drop.then(function () { return ready.promise(); }).then(function (r) { return r.update(); }).catch(function () {}).then(function () { location.reload(); });
    });
  });
})(jQuery, window, navigator);
