/*! Make4WebMail — sounds and alerts of the open app (settings: Notify.php) */
(function ($, window, document) {
  'use strict';
  var M4W = window.M4W;
  if (!M4W) return;
  var cfg = {};
  try { cfg = JSON.parse(($('#m4w-notify-cfg').text() || '{}')); } catch (e) { cfg = {}; }

  // ---------------------------------------------------------------- sounds
  // Plain audio files (classic ones of the application, or the person's own), played by an <audio>
  // element: what every browser plays, the iPhone in silent mode included (unlike Web Audio).
  cfg.sounds = cfg.sounds || {};
  var players = {};
  function player(name) {
    var url = cfg.sounds[name];
    if (!url) return null;
    var el = players[name];
    if (!el || el.__url !== url) { el = players[name] = new Audio(url); el.__url = url; el.preload = 'auto'; }
    return el;
  }
  // Browsers only let a page make sound once the person has used it: at the first touch, the
  // chosen sounds are played muted once, so that they may sound later, the page in the background.
  $(document).one('pointerup keydown', function () {
    [cfg.sound_new, cfg.sound_reminder].forEach(function (name) {
      var el = player(name);
      if (!el) return;
      el.muted = true;
      var p = el.play();
      // (Unless it was asked for meanwhile: the very first touch may be on "Listen".)
      var done = function () { if (!el.__live) { el.pause(); el.currentTime = 0; } el.muted = false; };
      if (p && p.then) p.then(done, function () { el.muted = false; }); else done();
    });
  });
  /**
   * Play a sound: "none", a classic one or one of the person's own ("own:12").
   * @returns {Promise} rejected with the browser's reason when it refuses to play
   */
  function play(name, volume) {
    volume = Math.max(0, Math.min(1, volume === undefined ? (+cfg.volume || 0) / 100 : volume));
    var el = name && name !== 'none' && volume ? player(name) : null;
    if (!el) return Promise.resolve();
    el.__live = true; el.muted = false; el.volume = volume;
    try { el.currentTime = 0; } catch (e) { /* not loaded yet */ }
    var p = el.play();
    return p && p.then ? p : Promise.resolve();
  }

  // ---------------------------------------------------------------- hours
  function inPeriod(now) {
    if (cfg.period !== 'custom') return true;
    now = now || new Date();
    var day = (now.getDay() + 6) % 7, part = String(cfg.hours || '').split(',')[day] || '';
    var m = /^(\d\d:\d\d)-(\d\d:\d\d)$/.exec(part);
    if (!m) return false;
    var hm = ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
    return hm >= m[1] && (hm < m[2] || m[2] === '23:59');
  }
  function vibrate() {
    var pat = { short: [200], long: [600], pulse: [200, 100, 200, 100, 200] }[cfg.vibrate];
    if (pat && navigator.vibrate) { try { navigator.vibrate(pat); } catch (e) { /* ignore */ } }
  }

  // ---------------------------------------------------------------- alerts
  var played = {};
  /**
   * New mail or a reminder seen by the open app: its sound (and vibration on a phone), once per
   * message even when both the server's push and the page's own check bring it.
   */
  /** @returns {Promise<boolean|null>} whether the sound played (null: nothing to announce) */
  function alert(kind, id, fromPush) {
    if (!cfg.on) return Promise.resolve(null);
    if (kind === 'new' && !cfg.notify_new) return Promise.resolve(null);
    if (kind === 'reminder' && !cfg.notify_reminder) return Promise.resolve(null);
    if (!inPeriod()) return Promise.resolve(null);
    var key = kind + ':' + (id || Math.floor(Date.now() / 20000));
    if (played[key]) return Promise.resolve(null);
    played[key] = Date.now();
    // Already announced by the system (its notification, sound and vibration): not twice.
    if (fromPush) return Promise.resolve(null);
    vibrate();
    var name = kind === 'reminder' ? cfg.sound_reminder : cfg.sound_new;
    if (!name || name === 'none') return Promise.resolve(true); // silence chosen: nothing to make up for
    return play(name).then(function () { return true; }, function () { return false; /* not allowed yet (no touch since loading) */ });
  }

  /**
   * New mail seen by the page: its sound, and, when the person is looking elsewhere (another
   * window, even beside this one; a hidden tab), the app's own notification, since the server
   * sends no system one to a device where the app is open. Silent if our sound played, with the
   * system's sound otherwise (the browser refused ours: no touch on the page since it loaded).
   * @param {{id:number,from_name:string,from_email:string,subject:string}} n
   * @param {function()} open what clicking it does
   */
  function announce(n, open) {
    alert('new', +n.id).then(function (sounded) {
      if (sounded === null || document.hasFocus() || !('Notification' in window) || Notification.permission !== 'granted') return;
      var title = M4W.t('notif_from', { name: n.from_name || n.from_email });
      var opts = { body: n.subject || M4W.t('no_subject'), tag: 'm4w-' + n.id, silent: !!sounded, icon: $('link[rel=apple-touch-icon]').attr('href'),
        data: { url: M4W.url('mail') + '#f/' + n.folder_id + '/m/' + n.id } };
      // Through the service worker: the only way on a phone (Android refuses new Notification()),
      // and its click opens the message like a pushed one (sw.js).
      var direct = function () {
        try {
          var no = new Notification(title, opts);
          no.onclick = function () { window.focus(); open(); no.close(); };
        } catch (e) { /* not allowed here */ }
      };
      if (navigator.serviceWorker && navigator.serviceWorker.controller) {
        navigator.serviceWorker.ready.then(function (reg) { return reg.showNotification(title, opts); }).catch(direct);
      } else {
        direct();
      }
    });
  }

  // ------------------------------------------------------------- heartbeat
  // Each check of the page tells the server the app is open on this device (its push
  // subscription, by a hash of its address): the server then sends no system notification here
  // and lets the app announce new mail itself, once its list shows it (see WebPush::send).
  var device = '';
  function findDevice() {
    if (!navigator.serviceWorker || !window.crypto || !crypto.subtle || !window.TextEncoder) return;
    navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager && reg.pushManager.getSubscription(); }).then(function (sub) {
      if (!sub) { device = ''; return; }
      return crypto.subtle.digest('SHA-256', new TextEncoder().encode(sub.endpoint)).then(function (h) {
        device = Array.prototype.map.call(new Uint8Array(h), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
      });
    }).catch(function () { /* no push here */ });
  }
  findDevice();
  setInterval(findDevice, 300000); // notifications turned on or off meanwhile

  /**
   * In the background (phone locked, another app or window in front, app closed): the server is
   * told at once, so the system notifies this device from now on, with its own sound, and sends
   * what it was holding for the page. A hidden page cannot show a notification on a phone; it
   * could only make a sound.
   */
  function away() { return document.hidden ? 1 : 0; }
  function tellAway() {
    if (!device || !window.fetch) return;
    try { fetch(M4W.url('api/stamp') + '?device=' + device + '&away=1', { credentials: 'same-origin', keepalive: true }); } catch (e) { /* closing */ }
  }
  document.addEventListener('visibilitychange', function () { if (document.hidden) tellAway(); });
  window.addEventListener('pagehide', tellAway);

  /** Something arrived: the open page checks at once (mail.js sets its own way, see refresh). */
  var refresh = function () { if (M4W.mailRefresh) M4W.mailRefresh(); };
  if (navigator.serviceWorker) {
    navigator.serviceWorker.addEventListener('message', function (e) {
      var d = e.data || {};
      // The system showed the notification (app in the background): no second sound.
      if (d.type === 'm4w-alert') { pushSeen = Date.now(); alert(d.kind, d.id, true); M4W.Notify.refresh(); }
      // The app is on screen: no system notification, the page announces it once its list shows it.
      if (d.type === 'm4w-push') { if (d.id) expected[+d.id] = 1; M4W.Notify.refresh(); }
      // In the background, still awake: the app's own sound now, the system's notification silent.
      if (d.type === 'm4w-play' && e.ports && e.ports[0]) {
        var port = e.ports[0], already = !!played[d.kind + ':' + (d.id || '')];
        alert(d.kind, d.id).then(function (sounded) { port.postMessage({ played: already || sounded === true }); }, function () { port.postMessage({ played: false }); });
      }
    });
  }

  // Reminders made here when this device gets no push (not subscribed): while mail stays unread.
  var pushSeen = 0, unreadSince = 0, lastReminder = 0, unread = 0;
  function setUnread(n) {
    unread = n;
    if (!n) { unreadSince = 0; lastReminder = 0; return; }
    if (!unreadSince) unreadSince = Date.now();
  }
  setInterval(function () {
    if (!cfg.on || !cfg.notify_reminder || !unread || !unreadSince) return;
    var every = (+cfg.reminder_minutes || 30) * 60000;
    if (Date.now() - pushSeen < every) return; // a system notification came meanwhile
    if (Date.now() - unreadSince < every || Date.now() - lastReminder < every || !inPeriod()) return;
    lastReminder = Date.now();
    alert('reminder', 'r' + lastReminder).then(function (sounded) {
      if (sounded === null || document.hasFocus() || !('Notification' in window) || Notification.permission !== 'granted') return;
      try { new Notification(M4W.t(unread > 1 ? 'notif_reminder_n' : 'notif_reminder_1', { n: unread }), { tag: 'm4w-reminder', renotify: true, silent: !!sounded, icon: $('link[rel=apple-touch-icon]').attr('href') }); } catch (e) { /* ignore */ }
    });
  }, 60000);

  // ---------------------------------------------------------------- badges
  var favicon = null;
  /** The unread total as a red bubble on the tab's icon. */
  function tabBadge(n) {
    var $link = $('link[rel="icon"]').first();
    if (!$link.length) return;
    if (!favicon) {
      favicon = { href: $link.attr('href'), img: null, ready: false, last: -1 };
      var img = new Image();
      img.onload = function () { favicon.img = img; favicon.ready = true; var w = favicon.last; favicon.last = -1; tabBadge(w); };
      img.src = favicon.href;
    }
    if (favicon.last === n) return;
    favicon.last = n;
    if (!n) { $link.attr('href', favicon.href); return; }
    if (!favicon.ready) return;
    var c = document.createElement('canvas'), s = 64;
    c.width = c.height = s;
    var x = c.getContext('2d');
    x.drawImage(favicon.img, 0, 0, s, s);
    var label = n > 99 ? '99+' : String(n), r = 18;
    x.font = 'bold ' + (label.length > 2 ? 22 : 30) + 'px system-ui, sans-serif';
    var w = Math.max(r * 2, x.measureText(label).width + 14), cx = s - w / 2, cy = r;
    x.fillStyle = '#ef4444';
    x.beginPath();
    x.moveTo(cx - w / 2 + r, cy - r); x.arcTo(cx + w / 2, cy - r, cx + w / 2, cy + r, r); x.arcTo(cx + w / 2, cy + r, cx - w / 2, cy + r, r);
    x.arcTo(cx - w / 2, cy + r, cx - w / 2, cy - r, r); x.arcTo(cx - w / 2, cy - r, cx + w / 2, cy - r, r);
    x.fill();
    x.fillStyle = '#fff'; x.textAlign = 'center'; x.textBaseline = 'middle';
    x.fillText(label, cx, cy + 2);
    try { $link.attr('href', c.toDataURL('image/png')); } catch (e) { /* picture from elsewhere */ }
  }

  // Pushed while the app was on screen (the service worker left it to the page): announced here.
  var expected = {};
  /** Whether the page announces this new message itself: held for it by the server, or handed over. */
  function mine(r, id) { return !r.held || r.held.indexOf(+id) !== -1 || !!expected[+id]; }

  M4W.Notify = {
    mine: mine,
    play: play, alert: alert, announce: announce, inPeriod: inPeriod, setUnread: setUnread, tabBadge: tabBadge, cfg: cfg,
    /** The hash of this device's push subscription, sent with each check (heartbeat). */
    device: function () { return device; },
    /** 1 when the page is hidden: its checks do not count as "open on this device". */
    away: away,
    refresh: function () { refresh(); }
  };

  // ------------------------------------------------- the other pages (not the mail)
  // Settings, calendar, files…: a light check every 20 s keeps the bubbles (avatar, account menu,
  // tab, installed app) up to date, and new mail is announced there too (toast, sound).
  $(function () {
    if (M4W.mailPage || !$('[data-boxes-elsewhere]').length) return;
    var since = 0, pulseSince = 0, brand = document.title.replace(/^\(\d+\)\s*/, '');
    function show(boxes, arrived) {
      var total = 0;
      Object.keys(boxes || {}).forEach(function (id) {
        var n = +boxes[id] || 0;
        total += n;
        $('[data-box-unread="' + id + '"]').text(n).toggleClass('d-none', !n);
      });
      var $t = $('[data-boxes-elsewhere]'), was = +$t.text() || 0;
      $t.text(total).toggleClass('d-none', !total);
      if (arrived && total > was) { $t.addClass('m4w-pulse'); pulseSince = Date.now(); }
      if (!total) $t.removeClass('m4w-pulse');
      document.title = (total ? '(' + total + ') ' : '') + brand;
      tabBadge(total);
      setUnread(total);
      try { if (navigator.setAppBadge) { total ? navigator.setAppBadge(total) : navigator.clearAppBadge(); } } catch (e) { /* not supported */ }
    }
    $(document).on('pointerdown keydown', function () {
      if (pulseSince && Date.now() - pulseSince > 1000) { $('[data-boxes-elsewhere]').removeClass('m4w-pulse'); pulseSince = 0; }
    });
    var checking = false, told = {};
    function check() {
      if (M4W.Offline && M4W.Offline.offline) return;
      if (checking) return; // one at a time (back in front, the timer, a push at once)
      checking = true;
      M4W.get('api/unread', { since: since, device: device, away: away() }, { silent: true, quiet: true }).always(function () { checking = false; }).done(function (r) {
        var news = since ? (r.new || []).filter(function (x) { return !told[x.id]; }) : [];
        news.forEach(function (x) { told[x.id] = 1; });
        show(r.boxes, news.length > 0);
        since = r.max_id || since;
        if (news.length) {
          var n = news[0];
          M4W.toast(M4W.t('new_mail_from', { name: n.from_name || n.from_email }) + ' — ' + (n.subject || M4W.t('no_subject')), 'info', {
            action: { label: M4W.t('open'), fn: function () { location.href = M4W.url('mail') + '#f/' + n.folder_id + '/m/' + n.id; } }
          });
          // Only what the server held for this page: the rest was announced by the system (WebPush::send).
          if (mine(r, n.id)) announce(n, function () { location.href = M4W.url('mail') + '#f/' + n.folder_id + '/m/' + n.id; });
        }
      });
    }
    refresh = check;
    check();
    setInterval(check, 20000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
  });


  // ------------------------------------------------------------- settings page
  $(function () {
    var $f = $('#m4w-notify-form');
    if (!$f.length) return;
    var volume = function () { return (+$f.find('[name=sound_volume]').val() || 0) / 100; };
    function listen(name) {
      if (name !== 'none' && !volume()) { M4W.toast(M4W.t('notif_volume_zero'), 'info'); return; }
      play(name, volume()).catch(function (e) {
        M4W.toast(M4W.t('notif_play_failed', { why: (e && (e.message || e.name)) || '?' }), 'danger');
      });
    }
    function sync() {
      var custom = $f.find('[name=notify_period]:checked').val() === 'custom';
      $f.find('[data-notify-hours]').toggleClass('d-none', !custom);
      $f.find('[data-reminder-opts]').toggleClass('d-none', !$f.find('[name=notify_reminder]').is(':checked'));
      var own = $f.find('[data-own]').length > 0;
      $f.find('[data-own-sounds]').prop('hidden', !own);
      $f.find('[data-own-list-row]').prop('hidden', !own);
    }
    $f.on('change', 'input, select', sync);
    sync();
    $f.on('click', '[data-listen]', function () { listen($f.find('[name=sound_' + $(this).data('listen') + ']').val()); });
    $f.on('click', '[data-own-play]', function () { listen($(this).closest('[data-own]').data('own')); });
    $f.on('click', '[data-try-vibrate]', function () {
      var pat = { short: [200], long: [600], pulse: [200, 100, 200, 100, 200] }[$f.find('[name=vibrate]').val()];
      if (pat && navigator.vibrate) navigator.vibrate(pat); else M4W.toast(M4W.t('notif_no_vibrate'), 'info');
    });
    // The new-mail sound, to save on the phone and give to its notifications (Android plays its own
    // sound when the app is closed: a web page cannot choose it).
    function downloadLink() {
      var name = $f.find('[name=sound_new]').val(), url = cfg.sounds[name];
      var label = $f.find('[name=sound_new] option:selected').text().trim() || name;
      $('[data-sound-download]').toggleClass('disabled', !url).attr('href', url || '#')
        .attr('download', ('webmail-' + label).replace(/[^\w.-]+/g, '-').toLowerCase() + (url && /\.mp3(\?|$)/i.test(url) ? '.mp3' : (url && /\.ogg(\?|$)/i.test(url) ? '.ogg' : '.wav')));
    }
    $f.on('change', '[name=sound_new]', downloadLink);
    downloadLink();
    // A sound of one's own: sent at once, added to every list, chosen here and played to check.
    $f.on('change', 'input[type=file][data-sound-file]', function () {
      var kind = $(this).data('sound-file'), file = this.files && this.files[0], input = this;
      if (!file) return;
      var fd = new FormData(); fd.append('file', file);
      M4W.api('POST', 'settings/sound', fd).done(function (r) {
        cfg.sounds[r.value] = r.url;
        $f.find('[data-own-sounds]').each(function () { $('<option>').val(r.value).text(r.label).appendTo(this); });
        $('<li class="d-flex align-items-center gap-2">').attr('data-own', r.value).append(
          '<i class="bi bi-music-note-beamed text-muted"></i>',
          $('<span class="flex-grow-1 text-truncate" style="max-width:260px">').text(r.label),
          $('<button type="button" class="btn btn-ghost btn-icon btn-sm" data-own-play><i class="bi bi-play-fill"></i></button>').attr({ title: M4W.t('notif_listen'), 'aria-label': M4W.t('notif_listen') }),
          $('<button type="button" class="btn btn-ghost btn-icon btn-sm text-danger" data-own-delete><i class="bi bi-trash3"></i></button>').attr({ title: M4W.t('delete'), 'aria-label': M4W.t('delete') })
        ).appendTo($f.find('[data-own-list]'));
        $f.find('[name=sound_' + kind + ']').val(r.value);
        sync();
        // Played to check: a file the browser cannot read is taken back at once.
        play(r.value, volume() || 0.5).then(function () {
          M4W.toast(M4W.t('notif_sound_saved'), 'success');
        }, function (e) {
          if (e && e.name === 'NotSupportedError') { remove(r.value); M4W.toast(M4W.t('notif_sound_unreadable'), 'danger'); return; }
          M4W.toast(M4W.t('notif_sound_saved'), 'success');
        });
      }).always(function () { input.value = ''; });
    });
    function remove(value) {
      return M4W.api('POST', 'settings/sound/delete', { id: String(value).replace('own:', '') }).done(function (r) {
        $f.find('[data-own="' + value + '"]').remove(); delete cfg.sounds[value]; delete players[value];
        $f.find('option[value="' + value + '"]').remove();
        $f.find('[name=sound_new]').val(r.sound_new); $f.find('[name=sound_reminder]').val(r.sound_reminder);
        sync();
      });
    }
    $f.on('click', '[data-own-delete]', function () {
      var $li = $(this).closest('[data-own]');
      M4W.confirm(M4W.t('notif_sound_delete', { name: $li.find('span').text() })).done(function () { remove($li.data('own')); });
    });
  });
})(jQuery, window, document);
