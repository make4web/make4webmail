/*! Make4WebMail — sounds and alerts of the open app (settings: Notify.php) */
(function ($, window, document) {
  'use strict';
  var M4W = window.M4W;
  if (!M4W) return;
  var cfg = {};
  try { cfg = JSON.parse(($('#m4w-notify-cfg').text() || '{}')); } catch (e) { cfg = {}; }

  // ---------------------------------------------------------------- sounds
  // Classic short sounds, made here (no file to download): notes of [frequency, start s, length s].
  var BUILT_IN = {
    ding: { type: 'sine', notes: [[1318.5, 0, 0.9]] },
    chime: { type: 'sine', notes: [[1046.5, 0, 0.5], [1318.5, 0.16, 0.5], [1568, 0.32, 0.8]] },
    bell: { type: 'triangle', notes: [[880, 0, 1.4], [1760, 0, 0.9], [2637, 0, 0.5]] },
    pop: { type: 'sine', notes: [[660, 0, 0.08], [990, 0.06, 0.12]] },
    marimba: { type: 'sine', notes: [[784, 0, 0.3], [988, 0.12, 0.3], [1175, 0.24, 0.45]] },
    beep: { type: 'square', notes: [[1000, 0, 0.12], [1000, 0.2, 0.12]] }
  };
  var ctx = null;
  function audio() {
    if (!ctx) { try { ctx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { ctx = null; } }
    return ctx;
  }
  // Browsers only let a page make sound after the person has used it: unlocked at the first touch.
  $(document).one('pointerdown keydown', function () { var a = audio(); if (a && a.state === 'suspended') a.resume(); });

  function synth(name, volume) {
    var def = BUILT_IN[name], a = audio();
    if (!def || !a) return;
    if (a.state === 'suspended') a.resume();
    var t0 = a.currentTime + 0.02, gainMax = 0.35 * volume;
    def.notes.forEach(function (n) {
      var o = a.createOscillator(), g = a.createGain();
      o.type = def.type; o.frequency.value = n[0];
      g.gain.setValueAtTime(0.0001, t0 + n[1]);
      g.gain.exponentialRampToValueAtTime(gainMax, t0 + n[1] + 0.015);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + n[1] + n[2]);
      o.connect(g); g.connect(a.destination);
      o.start(t0 + n[1]); o.stop(t0 + n[1] + n[2] + 0.05);
    });
  }
  var custom = {};
  /** Play a sound: a built-in name, "custom" (the person's own file for this kind), or none. */
  function play(name, kind, volume) {
    volume = Math.max(0, Math.min(1, volume === undefined ? (+cfg.volume || 70) / 100 : volume));
    if (!name || name === 'none' || !volume) return;
    if (name === 'custom') {
      var url = cfg['custom_' + kind];
      if (!url) { synth(kind === 'reminder' ? 'chime' : 'ding', volume); return; }
      var el = custom[kind] || (custom[kind] = new Audio(url));
      el.volume = volume; el.currentTime = 0;
      var p = el.play(); if (p && p.catch) p.catch(function () {});
      return;
    }
    synth(name, volume);
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
  function alert(kind, id, fromPush) {
    if (!cfg.on) return;
    if (kind === 'new' && !cfg.notify_new) return;
    if (kind === 'reminder' && !cfg.notify_reminder) return;
    if (!inPeriod()) return;
    var key = kind + ':' + (id || Math.floor(Date.now() / 20000));
    if (played[key]) return;
    played[key] = Date.now();
    play(kind === 'reminder' ? cfg.sound_reminder : cfg.sound_new, kind);
    if (!fromPush) vibrate(); // the system notification already vibrates for a push
  }
  if (navigator.serviceWorker) {
    navigator.serviceWorker.addEventListener('message', function (e) {
      var d = e.data || {};
      if (d.type === 'm4w-alert') { pushSeen = Date.now(); alert(d.kind, d.id, true); }
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
    if (Date.now() - pushSeen < 3 * 3600000) return; // the server's reminders reach this device
    var every = (+cfg.reminder_minutes || 30) * 60000;
    if (Date.now() - unreadSince < every || Date.now() - lastReminder < every || !inPeriod()) return;
    lastReminder = Date.now();
    alert('reminder', 'r' + lastReminder);
    if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
      try { new Notification(M4W.t(unread > 1 ? 'notif_reminder_n' : 'notif_reminder_1', { n: unread }), { tag: 'm4w-reminder', renotify: true, icon: $('link[rel=apple-touch-icon]').attr('href') }); } catch (e) { /* ignore */ }
    }
  }, 60000);

  M4W.Notify = { play: play, alert: alert, inPeriod: inPeriod, setUnread: setUnread, cfg: cfg };

  // ------------------------------------------------------------- settings page
  $(function () {
    var $f = $('#m4w-notify-form');
    if (!$f.length) return;
    function sync() {
      var custom = $f.find('[name=notify_period]:checked').val() === 'custom';
      $f.find('[data-notify-hours]').toggleClass('d-none', !custom);
      $f.find('[data-reminder-opts]').toggleClass('d-none', !$f.find('[name=notify_reminder]').is(':checked'));
      ['new', 'reminder'].forEach(function (k) {
        $f.find('[data-custom-' + k + ']').toggleClass('d-none', $f.find('[name=sound_' + k + ']').val() !== 'custom');
      });
    }
    $f.on('change', 'input, select', sync);
    sync();
    $f.on('click', '[data-listen]', function () {
      var k = $(this).data('listen');
      play($f.find('[name=sound_' + k + ']').val(), k, (+$f.find('[name=sound_volume]').val() || 0) / 100);
    });
    $f.on('click', '[data-try-vibrate]', function () {
      var pat = { short: [200], long: [600], pulse: [200, 100, 200, 100, 200] }[$f.find('[name=vibrate]').val()];
      if (pat && navigator.vibrate) navigator.vibrate(pat); else M4W.toast(M4W.t('notif_no_vibrate'), 'info');
    });
    // Own sound: sent at once, played to check.
    $f.on('change', 'input[type=file][data-sound-file]', function () {
      var k = $(this).data('sound-file'), file = this.files && this.files[0], input = this;
      if (!file) return;
      var fd = new FormData(); fd.append('kind', k); fd.append('file', file);
      M4W.api('POST', 'settings/sound', fd).done(function (r) {
        cfg['custom_' + k] = r.url; delete custom[k];
        $f.find('[data-sound-name="' + k + '"]').text(file.name);
        $f.find('[name=sound_' + k + ']').val('custom');
        sync(); play('custom', k, (+$f.find('[name=sound_volume]').val() || 0) / 100);
        M4W.toast(M4W.t('notif_sound_saved'), 'success');
      }).always(function () { input.value = ''; });
    });
  });
})(jQuery, window, document);
