/*! Make4WebMail — offline mode (server side: app/Service/Offline.php) */
(function ($, window, document) {
  'use strict';
  var M4W = window.M4W;
  if (!M4W) return;

  /*
   * What the device keeps, encrypted with its own key (AES-GCM, not exportable), in IndexedDB:
   *  - the latest 100 messages with their pictures, the lists of the folders, the calendar from
   *    -6 to +6 months: read without a connection;
   *  - the work done offline (actions, messages to send): replayed, in order, when the
   *    connection is back, each one checked and applied once by the server.
   * The server keeps a copy of the key: the device drops its own when the session ends (all it
   * holds becomes unreadable, nothing is lost) and gets it back when the same person signs in
   * again. Signing out erases everything.
   */
  function meta(n) { return $('meta[name="' + n + '"]').attr('content') || ''; }
  var USER = +meta('m4w-user') || 0;
  var ALLOWED = meta('m4w-offline') === '1';
  var supported = !!(window.indexedDB && window.crypto && window.crypto.subtle && window.isSecureContext && window.TextEncoder);
  var t = function (k, v) { return M4W.t('off_' + k, v); };

  var O = M4W.Offline = {
    offline: false, ready: false, locked: false, key: null, queue: [], cached: {}, changes: [],
    supported: supported, allowed: ALLOWED && supported && USER > 0
  };
  var readyDef = $.Deferred();
  O.whenReady = function () { return readyDef.promise(); };
  O.onChange = function (fn) { O.changes.push(fn); };
  function changed() { O.changes.forEach(function (fn) { try { fn(O); } catch (e) { /* ignore */ } }); renderIndicator(); }

  // ------------------------------------------------------------ storage
  var dbp = null;
  function dbName(uid) { return 'm4w-offline-' + uid; }
  function db() {
    if (!dbp) {
      var STORES = ['meta', 'data', 'queue'];
      var open = function (version) {
        return new Promise(function (resolve, reject) {
          var r = version ? indexedDB.open(dbName(USER), version) : indexedDB.open(dbName(USER));
          r.onupgradeneeded = function () { STORES.forEach(function (n) { if (!r.result.objectStoreNames.contains(n)) r.result.createObjectStore(n); }); };
          r.onsuccess = function () {
            var d = r.result;
            // A store missing (a database left half made, by an interrupted first opening):
            // opened again one version higher, which creates it.
            if (STORES.some(function (n) { return !d.objectStoreNames.contains(n); })) {
              var next = d.version + 1;
              d.close();
              open(next).then(resolve, reject);
              return;
            }
            resolve(d);
          };
          r.onerror = function () { reject(r.error); };
          r.onblocked = function () { reject(new Error('blocked')); };
        });
      };
      // The current version, whatever it is (a repaired database is above 1).
      dbp = open(0);
    }
    return dbp;
  }
  function req(store, mode, fn) {
    return db().then(function (d) {
      return new Promise(function (resolve, reject) {
        var tx = d.transaction(store, mode), s = tx.objectStore(store), out;
        var r = fn(s);
        if (r) r.onsuccess = function () { out = r.result; };
        tx.oncomplete = function () { resolve(out); };
        tx.onerror = tx.onabort = function () { reject(tx.error); };
      });
    });
  }
  var idb = {
    get: function (s, k) { return req(s, 'readonly', function (st) { return st.get(k); }); },
    put: function (s, k, v) { return req(s, 'readwrite', function (st) { return st.put(v, k); }); },
    del: function (s, k) { return req(s, 'readwrite', function (st) { return st.delete(k); }); },
    keys: function (s) { return req(s, 'readonly', function (st) { return st.getAllKeys(); }); },
    clear: function (s) { return req(s, 'readwrite', function (st) { return st.clear(); }); }
  };

  // ------------------------------------------------------------- crypto
  var enc8 = new TextEncoder(), dec8 = new TextDecoder();
  function b64uBytes(s) {
    var raw = atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }
  // Each record is bound to its name (additional data): one cannot be swapped for another.
  function seal(name, value) {
    if (!O.key) return Promise.reject(new Error('locked'));
    var iv = crypto.getRandomValues(new Uint8Array(12));
    return crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: enc8.encode(name) }, O.key, enc8.encode(JSON.stringify(value)))
      .then(function (ct) { return { iv: iv, ct: ct }; });
  }
  function open(name, rec) {
    if (!O.key || !rec) return Promise.resolve(null);
    return crypto.subtle.decrypt({ name: 'AES-GCM', iv: rec.iv, additionalData: enc8.encode(name) }, O.key, rec.ct)
      .then(function (b) { return JSON.parse(dec8.decode(b)); }, function () { return null; }); // altered: ignored
  }
  function save(name, value) { return seal(name, value).then(function (rec) { return idb.put('data', name, rec); }); }
  function load(name) { return idb.get('data', name).then(function (rec) { return open(name, rec); }); }

  // ------------------------------------------------------------- device
  function device() {
    var d = '';
    try { d = localStorage.getItem('m4w.device') || ''; } catch (e) { d = ''; }
    if (!/^[A-Za-z0-9_-]{16,64}$/.test(d)) {
      var b = crypto.getRandomValues(new Uint8Array(24));
      d = btoa(String.fromCharCode.apply(null, b)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
      try { localStorage.setItem('m4w.device', d); } catch (e) { /* private mode: a new device each time */ }
    }
    return d;
  }
  var DEVICE = supported ? device() : '';
  function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    var b = crypto.getRandomValues(new Uint8Array(16)), h = '';
    for (var i = 0; i < 16; i++) h += (b[i] + 256).toString(16).slice(1);
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }
  function now() { return Math.floor(Date.now() / 1000); }

  // ------------------------------------------------- lock, wipe, session
  /** Session over: the device's key is dropped, what it keeps is unreadable (not lost). */
  O.lock = function () {
    O.key = null; O.locked = true;
    if (!O.allowed) return Promise.resolve();
    return idb.del('meta', 'key').catch(function () {}).then(changed);
  };
  /** Signing out: everything this person kept on the device is erased. */
  O.wipe = function () {
    O.key = null; O.queue = []; O.cached = {};
    try { if (navigator.serviceWorker && navigator.serviceWorker.controller) navigator.serviceWorker.controller.postMessage({ type: 'wipe' }); } catch (e) { /* ignore */ }
    var done = Promise.resolve();
    if (dbp) done = dbp.then(function (d) { d.close(); }).catch(function () {});
    dbp = null;
    return done.then(function () {
      return new Promise(function (resolve) { var r = indexedDB.deleteDatabase(dbName(USER)); r.onsuccess = r.onerror = r.onblocked = function () { resolve(); }; });
    });
  };
  // Another person's data on this browser (signed in before, session not closed here): locked.
  function lockOthers() {
    if (!indexedDB.databases) return;
    indexedDB.databases().then(function (list) {
      (list || []).forEach(function (d) {
        var m = /^m4w-offline-(\d+)$/.exec(d.name || '');
        if (!m || +m[1] === USER) return;
        var r = indexedDB.open(d.name);
        r.onsuccess = function () {
          var x = r.result;
          if (x.objectStoreNames.contains('meta')) { var tx = x.transaction('meta', 'readwrite'); tx.objectStore('meta').delete('key'); tx.oncomplete = function () { x.close(); }; } else { x.close(); }
        };
      });
    }).catch(function () {});
  }
  // The limits of the session apply offline too: app closed longer than the idle time, or the
  // session older than its maximum length, and the device locks itself.
  function sessionOver(s, active) {
    if (!s) return false;
    var n = now();
    return (active && n - active > s.idle) || n - s.login_at > s.max;
  }
  function touch() { if (O.allowed && O.ready) idb.put('meta', 'active', now()).catch(function () {}); }

  // ------------------------------------------------------------- start
  O.start = function () {
    if (!supported) return;
    if (!USER) { lockOthers(); return; } // sign-in page: nobody's data readable here
    lockOthers();
    if (!ALLOWED) {
      // Not allowed (any more), or someone else's mailbox: nothing kept; pending work still goes.
      idb.keys('queue').then(function (k) { if (!k || !k.length) return O.wipe(); }).catch(function () {});
      return;
    }
    Promise.all([idb.get('meta', 'key'), idb.get('meta', 'session'), idb.get('meta', 'active')]).then(function (r) {
      var key = r[0], session = r[1], active = r[2];
      if (key && sessionOver(session, active)) { key = null; idb.del('meta', 'key'); }
      O.key = key || null;
      O.locked = !key;
      O.session = session;
      return loadQueue();
    }).then(function () {
      return idb.keys('data').then(function (keys) {
        (keys || []).forEach(function (k) { var m = /^body:(\d+)$/.exec(k); if (m) O.cached[m[1]] = true; });
      });
    }).catch(function () { O.locked = true; }).then(function () {
      O.ready = true;
      readyDef.resolve();
      if (navigator.onLine === false) goneOffline(); else connect();
      changed();
    });
    setInterval(touch, 30000);
    setInterval(function () { if (O.offline && O.key && O.session && sessionOver(O.session, 0)) O.lock(); }, 60000);
    window.addEventListener('offline', goneOffline);
    window.addEventListener('online', probe);
  };

  /** Online and signed in: the device's key (the same as before), then the cache and the replay. */
  function connect() {
    M4W.post('api/offline/key', { device: DEVICE }, { silent: true, quiet: true, noOffline: true }).done(function (r) {
      if (!r.enabled) { O.allowed = false; changed(); return; }
      crypto.subtle.importKey('raw', b64uBytes(r.key), 'AES-GCM', false, ['encrypt', 'decrypt']).then(function (k) {
        O.key = k; O.locked = false; O.session = r.session; O.manifest = r.manifest;
        return Promise.all([idb.put('meta', 'key', k), idb.put('meta', 'session', r.session), idb.put('meta', 'active', now())]);
      }).then(loadQueue).then(function () {
        changed();
        replay();
        setTimeout(build, 3000);
        warmPages();
      }).catch(function () { /* storage refused: no offline mode on this device */ });
    });
  }
  function warmPages() {
    if (!navigator.serviceWorker) return;
    navigator.serviceWorker.ready.then(function (reg) { if (reg.active) reg.active.postMessage({ type: 'warm' }); }).catch(function () {});
  }

  // ---------------------------------------------------- connection state
  var probeTimer = null;
  function goneOffline() {
    if (!O.allowed) return;
    if (!O.offline) { O.offline = true; $('body').addClass('m4w-offline'); greyOut(true); changed(); }
    clearInterval(probeTimer);
    probeTimer = setInterval(probe, 20000);
  }
  function probe() {
    M4W.get('api/ping', null, { silent: true, quiet: true, noOffline: true }).done(backOnline).fail(function (x) {
      if (x && x.status && x.status !== 0) backOnline(); // the server answers (even with an error): online
      else goneOffline();
    });
  }
  function backOnline() {
    clearInterval(probeTimer);
    if (!O.offline) return;
    O.offline = false;
    $('body').removeClass('m4w-offline');
    greyOut(false);
    changed();
    if (!O.key) connect(); else replay();
  }
  O.goneOffline = goneOffline;

  // ---------------------------------------------- what M4W.api asks here
  var OFFLINE_POST = ['api/messages/action', 'api/compose/send', 'api/compose/draft'];
  /** Whether a request is answered here instead of the network. */
  O.handles = function (method, path, opts) { return O.allowed && O.offline && !(opts && opts.noOffline); };
  /** Whether a request that failed for lack of network may be answered here. */
  O.fallback = function (method, path) { return O.allowed && (method === 'GET' || OFFLINE_POST.indexOf(path) !== -1); };

  function unavailable(msg, quiet) {
    if (!quiet) M4W.toast(msg || t('unavailable'), 'warning');
    return $.Deferred().reject({ status: 0, responseJSON: { ok: false, offline: true, error: msg || t('unavailable') } }).promise();
  }
  function fromPromise(p) { var d = $.Deferred(); p.then(d.resolve, d.reject); return d.promise(); }

  O.request = function (method, path, data, opts) {
    opts = opts || {};
    return readyDef.then(function () {
      if (!O.key) return unavailable(O.locked ? t('locked_short') : t('nothing_kept'), opts.quiet);
      if (method === 'GET') return fromPromise(read(path, data || {})).then(function (res) {
        return res ? res : unavailable(null, opts.quiet);
      });
      if (OFFLINE_POST.indexOf(path) === -1) return unavailable(null, opts.quiet);
      return fromPromise(enqueue(path, data || {}));
    });
  };

  // --------------------------------------------------- reading offline
  function listKey(p) {
    return 'list:' + (+p.folder || 0) + ':' + (+p.threads ? 1 : 0) + ':' + (p.filter || '') + ':' + (+p.offset || 0);
  }
  function read(path, p) {
    var m;
    if (path === 'api/bootstrap') {
      return Promise.all([load('boot'), load('folders')]).then(function (r) {
        if (!r[0]) return null;
        if (r[1]) r[0].folders = r[1];
        r[0].offline_copy = true;
        return r[0];
      });
    }
    if (path === 'api/poll') return load('folders').then(function (f) { return f ? { ok: true, folders: f, new: [], max_id: +p.since || 0, stamp: '' } : null; });
    if (path === 'api/stamp') return Promise.resolve({ ok: true, stamp: '' });
    if (path === 'api/folders') return load('folders').then(function (f) { return f ? { ok: true, folders: f } : null; });
    if (path === 'api/messages') {
      if (p.q) return Promise.resolve(null); // the search needs the server
      return load(listKey(p)).then(function (l) {
        if (l || +p.offset) return l;
        return load(listKey($.extend({}, p, { threads: +p.threads ? 0 : 1 }))); // the other display, better than nothing
      });
    }
    if ((m = /^api\/messages\/(\d+)$/.exec(path))) {
      return load('msg:' + m[1] + ':' + (+p.thread ? 1 : 0)).then(function (r) { return r || load('msg:' + m[1] + ':' + (+p.thread ? 0 : 1)); });
    }
    if (path === 'api/compose') return prefill(p);
    if (path === 'api/calendar/events') {
      return load('cal').then(function (c) {
        if (!c) return null;
        var from = +p.from, to = +p.to, cals = String(p.cals || '').split(',').filter(Boolean).map(Number);
        return { ok: true, offline_copy: true, events: (c.events || []).filter(function (e) {
          return e.start < to && e.end > from && (!cals.length || cals.indexOf(+e.calendar_id) !== -1);
        }) };
      });
    }
    return Promise.resolve(null);
  }

  /** Answers of the server kept as they come (only what the offline mode can show). */
  O.capture = function (path, p, res) {
    if (!O.key || !res) return;
    p = p || {};
    var m;
    if (path === 'api/bootstrap') { save('boot', res); if (res.folders) save('folders', res.folders); }
    else if (path === 'api/poll' || path === 'api/folders') { if (res.folders) save('folders', res.folders); }
    else if (path === 'api/messages' && !p.q) save(listKey(p), res);
    else if ((m = /^api\/messages\/(\d+)$/.exec(path))) save('msg:' + m[1] + ':' + (+p.thread ? 1 : 0), res);
    else if (path === 'api/calendar/events' && res.events) mergeEvents(+p.from, +p.to, String(p.cals || ''), res.events);
    else if (path === 'api/compose' && (p.mode || 'new') === 'new' && !p.to && !p.subject) save('compose:new', res);
  };

  // The message form offline, as the server fills it: a new one (signature kept from the last
  // time online), or a reply / forward made from the message kept on the device.
  function fmtAddr(a) { return a.name ? '"' + String(a.name).replace(/"/g, '') + '" <' + a.email + '>' : a.email; }
  function prefill(p) {
    var mode = p.mode || 'new';
    return Promise.all([load('compose:new'), load('boot')]).then(function (r) {
      var base = r[0] || { ok: true, signature: '', compose: { mode: 'new', to: '', cc: '', bcc: '', subject: '', html: '', ref_id: 0, ref_attachments: [], attachments: [], draft_id: 0, from: (r[1] && r[1].user && r[1].user.email) || '', priority: 3, receipt: 0, attach_original: 0, scheduled_at: 0, schedule_error: '' } };
      var out = JSON.parse(JSON.stringify(base));
      out.compose.to = p.to || ''; out.compose.subject = p.subject || '';
      if (mode === 'new') return out;
      if (['reply', 'reply_all', 'forward'].indexOf(mode) === -1 || !p.id) return null; // drafts: need the server
      return Promise.all([load('msg:' + p.id + ':0').then(function (m) { return m || load('msg:' + p.id + ':1'); }), load('body:' + p.id)]).then(function (x) {
        var msg = x[0] && x[0].message;
        if (!msg) return null;
        var me = ((r[1] && r[1].identities) || []).map(function (i) { return String(i.email || i).toLowerCase(); });
        var fr = (document.documentElement.lang || 'fr').indexOf('fr') === 0;
        var clean = String(msg.subject || '').replace(/^\s*((re|tr|fwd?|aw|wg)\s*:\s*)+/i, '');
        var body = /<body[^>]*>([\s\S]*)<\/body>/i.exec(x[1] || '');
        // Pictures kept inside the copy are not sent again with the answer.
        var quoted = (body ? body[1] : M4W.esc(msg.snippet || '')).replace(/<img\b[^>]*\ssrc="data:[^"]*"[^>]*>/gi, '');
        var dateStr = new Date((msg.date || 0) * 1000).toLocaleString(document.documentElement.lang || 'fr', { dateStyle: 'full', timeStyle: 'short' });
        var c = out.compose;
        c.mode = mode; c.ref_id = +p.id;
        if (mode === 'forward') {
          c.subject = (fr ? 'TR: ' : 'Fwd: ') + clean;
          c.html = '<div><br></div><div data-m4w-quote="1"><div style="color:#475569;margin:12px 0">---------- ' + (fr ? 'Message transféré' : 'Forwarded message') + ' ----------<br>'
            + '<b>' + (fr ? 'De' : 'From') + ' :</b> ' + M4W.esc(fmtAddr(msg.from)) + '<br><b>Date :</b> ' + M4W.esc(dateStr) + '<br><b>' + (fr ? 'Objet' : 'Subject') + ' :</b> ' + M4W.esc(msg.subject || '')
            + '<br><b>' + (fr ? 'À' : 'To') + ' :</b> ' + M4W.esc((msg.to || []).map(fmtAddr).join(', ')) + '</div>' + quoted + '</div>';
          c.offline_note = t('forward_no_attachments');
          return out;
        }
        var to = (msg.reply_to && msg.reply_to.length ? msg.reply_to : [msg.from]);
        if (me.indexOf(String(msg.from.email).toLowerCase()) !== -1) to = msg.to || to; // one's own message: its recipients
        var cc = [];
        if (mode === 'reply_all') {
          var seen = to.map(function (a) { return String(a.email).toLowerCase(); });
          (msg.to || []).concat(msg.cc || []).forEach(function (a) {
            var k = String(a.email).toLowerCase();
            if (me.indexOf(k) === -1 && seen.indexOf(k) === -1) { cc.push(a); seen.push(k); }
          });
        }
        c.to = to.map(fmtAddr).join(', '); c.cc = cc.map(fmtAddr).join(', ');
        c.subject = (fr ? 'RE: ' : 'Re: ') + clean;
        c.html = '<div><br></div><div data-m4w-quote="1"><div style="color:#64748b;margin:12px 0 6px">' + M4W.esc(fr ? 'Le ' + dateStr + ', ' + fmtAddr(msg.from) + ' a écrit :' : 'On ' + dateStr + ', ' + fmtAddr(msg.from) + ' wrote:') + '</div>'
          + '<blockquote style="margin:0 0 0 .8ex;border-left:2px solid #cbd5e1;padding-left:1ex">' + quoted + '</blockquote></div>';
        (msg.to || []).concat(msg.cc || []).some(function (a) { if (me.indexOf(String(a.email).toLowerCase()) !== -1) { c.from = String(a.email).toLowerCase(); return true; } return false; });
        return out;
      });
    });
  }
  function mergeEvents(from, to, cals, events) {
    var only = cals.split(',').filter(Boolean).map(Number);
    return load('cal').then(function (c) {
      c = c || { events: [] };
      c.events = (c.events || []).filter(function (e) { return !(e.start < to && e.end > from && (!only.length || only.indexOf(+e.calendar_id) !== -1)); }).concat(events);
      return save('cal', c);
    });
  }
  O.hasMsg = function (id) { return !!O.cached[String(id)]; };

  // Message contents for the reader: the kept document, its pictures inside (no network needed).
  O.fillBodies = function ($ctx) {
    $ctx.find('iframe[data-offline-body]').each(function () {
      var f = this, id = $(f).attr('data-offline-body');
      $(f).removeAttr('data-offline-body');
      load('body:' + id).then(function (html) {
        f.srcdoc = html || '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;font-size:14px;color:#6b7280">' + M4W.esc(t('msg_unavailable')) + '</body>';
      });
    });
  };

  // ------------------------------------------------- building the cache
  var building = false;
  function build(force) {
    if (!O.key || O.offline || building) return;
    building = true;
    var opt = { silent: true, quiet: true, noOffline: true }, prefs = (M4W.state && M4W.state.prefs) || {};
    var get = function (p, d) { return new Promise(function (resolve) { M4W.get(p, d, opt).done(resolve).fail(function () { resolve(null); }); }); };
    idb.get('meta', 'built').then(function (at) {
      if (!force && at && now() - at < 600) return null;
      return (O.manifest ? Promise.resolve({ enabled: true, messages: O.manifest.messages, calendar: O.manifest.calendar }) : get('api/offline/manifest')).then(function (man) {
        if (!man || !man.enabled) return null;
        return get('api/bootstrap').then(function (boot) {
          get('api/compose', { mode: 'new' }); // the empty form and the signature, for writing offline
          var folders = (boot && boot.folders) || [], conv = boot && boot.user && boot.user.prefs ? boot.user.prefs.conversations : prefs.conversations;
          var size = (boot && boot.user && boot.user.prefs && boot.user.prefs.page_size) || prefs.page_size || 50;
          // The lists of the folders one works in (first page).
          var lists = folders.filter(function (f) { return f.role === 'inbox' || f.role === 'sent' || f.role === 'archive' || f.role === 'drafts' || !f.role; });
          return seqEach(lists, function (f) {
            var threads = conv && f.role !== 'sent' && f.role !== 'drafts' ? 1 : 0;
            return get('api/messages', { folder: f.id, q: '', filter: '', offset: 0, sort: 'date', dir: 'desc', threads: threads, limit: size });
          }).then(function () {
            // The latest messages and their contents, pictures included.
            var keep = {};
            return seqEach(man.messages || [], function (id) {
              if (O.offline || !O.key) return Promise.reject(new Error('stop'));
              keep[id] = true;
              var thread = conv ? 1 : 0;
              return get('api/messages/' + id, { thread: thread }).then(function (r) {
                var ids = [id];
                ((r && r.thread) || []).forEach(function (x) { if (x && x.id && ids.length < 6) ids.push(+x.id); });
                return seqEach(ids, function (mid) { keep[mid] = true; return O.cached[mid] ? null : cacheBody(mid); });
              });
            }).then(function () { return prune(keep); });
          }).then(function () {
            // The calendar from -6 to +6 months, in pieces the server accepts (120 days at most).
            var start = Math.floor(new Date(man.calendar.start + 'T00:00:00').getTime() / 1000), end = Math.floor(new Date(man.calendar.end + 'T00:00:00').getTime() / 1000), parts = [];
            for (var a = start; a < end; a += 100 * 86400) parts.push([a, Math.min(end, a + 100 * 86400)]);
            return save('cal', { events: [] }).then(function () {
              return seqEach(parts, function (r) { return get('api/calendar/events', { from: r[0], to: r[1], cals: '' }); });
            });
          }).then(function () { return idb.put('meta', 'built', now()); });
        });
      });
    }).catch(function () {}).then(function () { building = false; changed(); });
  }
  O.build = build;
  function seqEach(list, fn) {
    return list.reduce(function (p, x) { return p.then(function () { return fn(x); }); }, Promise.resolve());
  }
  /** One message's document, with its pictures (cleaned copies of our server) inside. */
  function cacheBody(id) {
    return fetch(M4W.url('api/messages/' + id + '/body'), { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) return null;
      return r.text().then(function (html) {
        var urls = {}, re = /<img\b[^>]*?\ssrc="([^"]+)"/gi, m, base = location.origin;
        while ((m = re.exec(html))) {
          var raw = m[1], u = raw.replace(/&amp;/g, '&');
          if (/^data:/i.test(u)) continue;
          var abs; try { abs = new URL(u, location.href); } catch (e) { continue; }
          if (abs.origin === base) urls[raw] = abs.href;
        }
        var total = 0;
        return seqEach(Object.keys(urls), function (raw) {
          return fetch(urls[raw], { credentials: 'same-origin' }).then(function (ir) {
            if (!ir.ok) return null;
            return ir.blob().then(function (b) {
              if (b.size > 3 * 1048576 || (total += b.size) > 10 * 1048576) return null;
              return new Promise(function (resolve) {
                var fr = new FileReader();
                fr.onload = function () { html = html.split('src="' + raw + '"').join('src="' + fr.result + '"'); resolve(); };
                fr.onerror = function () { resolve(); };
                fr.readAsDataURL(b);
              });
            });
          }).catch(function () {});
        }).then(function () { return save('body:' + id, html).then(function () { O.cached[id] = true; }); });
      });
    }).catch(function () {});
  }
  /** Messages no longer among the latest: removed from the device. */
  function prune(keep) {
    return idb.keys('data').then(function (keys) {
      return seqEach(keys || [], function (k) {
        var m = /^(?:body|msg):(\d+)/.exec(k);
        if (m && !keep[m[1]]) { delete O.cached[m[1]]; return idb.del('data', k); }
        return null;
      });
    });
  }

  // ---------------------------------------------------------- the queue
  function loadQueue() {
    if (!O.key) { return idb.keys('queue').then(function (k) { O.lockedCount = (k || []).length; O.queue = []; }); }
    return idb.keys('queue').then(function (keys) {
      return Promise.all((keys || []).map(function (k) {
        return idb.get('queue', k).then(function (rec) { return open('q:' + k, rec); }).then(function (it) {
          // Not readable with this person's key (altered, or slipped in by someone else): removed, never replayed.
          if (!it || it.id !== k) { idb.del('queue', k); return null; }
          return it;
        });
      }));
    }).then(function (items) {
      // Done more than a day ago: no longer listed.
      var old = (items || []).filter(function (x) { return x && x.done_at && x.status !== 'failed' && now() - x.done_at > 86400; });
      old.forEach(function (x) { idb.del('queue', x.id); });
      O.queue = (items || []).filter(function (x) { return x && old.indexOf(x) === -1; }).sort(function (a, b) { return a.seq - b.seq; });
      O.lockedCount = 0;
    });
  }
  function storeItem(it) { return seal('q:' + it.id, it).then(function (rec) { return idb.put('queue', it.id, rec); }); }
  function dropItem(it) { O.queue = O.queue.filter(function (x) { return x.id !== it.id; }); return idb.del('queue', it.id); }
  function nextSeq() {
    return idb.get('meta', 'seq').then(function (s) { var n = (+s || 0) + 1; return idb.put('meta', 'seq', n).then(function () { return n; }); });
  }
  O.pending = function () { return O.queue.filter(function (x) { return x.status === 'pending' || x.status === 'failed' || x.status === 'review'; }).length + (O.lockedCount || 0); };

  function label(path, d) {
    if (path === 'api/compose/send') return t('q_send', { subject: d.subject || M4W.t('no_subject'), to: summary(d.to) });
    if (path === 'api/compose/draft') return t('q_draft', { subject: d.subject || M4W.t('no_subject') });
    var n = (d.ids || []).length;
    return t('q_' + (d.action || 'action'), { n: n }) || d.action;
  }
  function summary(to) {
    var list = Array.isArray(to) ? to : String(to || '').split(',');
    return list.map(function (x) { return typeof x === 'object' ? (x.email || x.name || '') : String(x).trim(); }).filter(Boolean).slice(0, 3).join(', ');
  }

  /** Work done offline: kept (encrypted), applied here at once, replayed later. */
  function enqueue(path, data) {
    data = JSON.parse(JSON.stringify(data));
    var p = Promise.resolve();
    // The same action on the same messages, still waiting: once is enough.
    if (path === 'api/messages/action') {
      var sig = JSON.stringify([data.action, (data.ids || []).map(Number).sort(), +data.folder || 0]);
      var same = O.queue.filter(function (x) { return x.path === path && x.status === 'pending' && JSON.stringify([x.data.action, (x.data.ids || []).map(Number).sort(), +x.data.folder || 0]) === sig; })[0];
      if (same) return applyLocally(path, data).then(function (folders) { return { ok: true, offline: true, count: (data.ids || []).length, ids: data.ids || [], folders: folders || undefined }; });
    }
    // Drafts of the same message: only the latest is kept; once it is sent, none.
    if (data.local_id && (path === 'api/compose/draft' || path === 'api/compose/send')) {
      O.queue.filter(function (x) { return x.path === 'api/compose/draft' && x.data.local_id === data.local_id && x.status === 'pending'; }).forEach(function (x) { p = p.then(function () { return dropItem(x); }); });
    }
    return p.then(nextSeq).then(function (seq) {
      var it = { id: uuid(), seq: seq, at: now(), path: path, data: data, status: path === 'api/compose/send' ? 'review' : 'pending', label: label(path, data) };
      O.queue.push(it);
      return storeItem(it).then(function () { return applyLocally(path, data); }).then(function (folders) {
        changed();
        if (path === 'api/compose/send') return { ok: true, id: 0, offline: true, queued: true };
        if (path === 'api/compose/draft') return { ok: true, draft_id: +data.draft_id || 0, saved_at: now(), offline: true };
        return { ok: true, offline: true, count: (data.ids || []).length, ids: data.ids || [], folders: folders || undefined };
      });
    });
  }

  /** An action done offline shown in the kept lists and counters (a reload offline shows it). */
  function applyLocally(path, d) {
    if (path !== 'api/messages/action' || !d.ids || !d.ids.length) return Promise.resolve(null);
    var ids = d.ids.map(Number), a = d.action, flags = { read: ['unread', false], unread: ['unread', true], flag: ['flagged', true], unflag: ['flagged', false], pin: ['pinned', true], unpin: ['pinned', false] };
    var removes = ['move', 'delete', 'archive', 'spam', 'inbox', 'not_spam', 'purge'].indexOf(a) !== -1, delta = {};
    // The kept copy of the message knows it was read (opened again, it is not marked twice).
    var msgKeys = [];
    if (a === 'read' || a === 'unread') ids.forEach(function (id) { msgKeys.push('msg:' + id + ':0', 'msg:' + id + ':1'); });
    return seqEach(msgKeys, function (k) {
      return load(k).then(function (r) {
        if (!r) return null;
        r.unread_ids = a === 'read' ? (r.unread_ids || []).filter(function (x) { return ids.indexOf(+x) === -1; }) : (r.unread_ids || []).concat(ids.filter(function (x) { return (r.unread_ids || []).indexOf(x) === -1; }));
        return save(k, r);
      });
    }).then(function () { return idb.keys('data'); }).then(function (keys) {
      return seqEach((keys || []).filter(function (k) { return /^list:/.test(k); }), function (k) {
        var folder = +k.split(':')[1];
        return load(k).then(function (l) {
          if (!l || !l.items) return null;
          var touched = false;
          l.items = l.items.filter(function (it) {
            if (ids.indexOf(+it.id) === -1) return true;
            touched = true;
            if (removes) { if (it.unread) delta[folder] = (delta[folder] || 0) - 1; l.total = Math.max(0, (l.total || 1) - 1); return false; }
            if (flags[a]) {
              if (a === 'read' && it.unread) delta[folder] = (delta[folder] || 0) - 1;
              if (a === 'unread' && !it.unread) delta[folder] = (delta[folder] || 0) + 1;
              it[flags[a][0]] = flags[a][1];
            }
            return true;
          });
          return touched ? save(k, l) : null;
        });
      });
    }).then(function () {
      return load('folders').then(function (f) {
        if (!f) return null;
        f.forEach(function (x) { if (delta[x.id]) x.unread = Math.max(0, (+x.unread || 0) + delta[x.id]); });
        return save('folders', f).then(function () { return f; });
      });
    });
  }

  // --------------------------------------------------------- the replay
  var replaying = false;
  function replay() {
    if (replaying || O.offline || !O.key) return;
    var todo = O.queue.filter(function (x) { return x.status === 'pending' || x.status === 'failed' || x.status === 'review'; });
    if (!todo.length) return;
    var sends = todo.filter(function (x) { return x.status === 'review'; });
    // Messages written offline do not leave unseen: the person checks them first.
    var ok = sends.length ? review(sends) : Promise.resolve();
    ok.then(function () {
      replaying = true; changed();
      // A fresh security token first (the page may come from the kept copy).
      return new Promise(function (resolve) { M4W.get('api/poll', null, { silent: true, quiet: true, noOffline: true }).always(resolve); });
    }).then(function () {
      return seqEach(O.queue.filter(function (x) { return x.status === 'pending' || x.status === 'failed'; }), function (it) {
        if (O.offline || !O.key) return Promise.reject(new Error('stop'));
        return send(it);
      });
    }).catch(function () {}).then(function () {
      replaying = false;
      changed();
      if (M4W.reloadList) M4W.reloadList();
    });
  }
  O.replay = replay;

  function send(it) {
    it.status = 'sending';
    return new Promise(function (resolve, reject) {
      M4W.api('POST', it.path, it.data, { silent: true, quiet: true, noOffline: true, offlineStamp: { device: DEVICE, id: it.id, seq: it.seq, at: it.at } })
        .done(function (r) {
          it.status = 'applied';
          it.done_at = now();
          it.error = '';
          if (it.path === 'api/compose/send') {
            it.result = r && r.deferred ? 'deferred' : 'sent';
            if (r && r.warnings && Object.keys(r.warnings).length) { it.result = 'partial'; it.error = Object.keys(r.warnings).join(', '); }
          }
          storeItem(it).then(resolve, resolve);
        })
        .fail(function (x) {
          var r = (x && x.responseJSON) || {};
          if (!x || x.status === 0) { it.status = 'failed'; it.error = t('err_network'); storeItem(it); goneOffline(); return reject(new Error('offline')); }
          if (x.status === 401 || r.login) { it.status = 'pending'; return reject(new Error('session')); }
          if (r.offline === 'in_progress') { it.status = 'pending'; return reject(new Error('busy')); }
          // Refused by the server (too old, out of order, message gone…): kept with the reason.
          it.status = r.offline ? 'ignored' : 'failed';
          it.error = r.offline ? t('why_' + r.offline) : (r.error || t('err_generic'));
          it.done_at = now();
          storeItem(it).then(resolve, resolve);
        });
    });
  }

  /** Before they leave: the messages written offline, to check (send, or keep for later). */
  function review(items) {
    return new Promise(function (resolve, reject) {
      $('#m4w-off-review').remove();
      var rows = items.map(function (it) {
        return '<label class="m4w-off-review-item"><input class="form-check-input" type="checkbox" checked data-id="' + M4W.esc(it.id) + '"><span><b>' + M4W.esc(it.data.subject || M4W.t('no_subject')) + '</b><small>'
          + M4W.esc(t('to_whom', { to: summary(it.data.to) })) + ' · ' + M4W.esc(new Date(it.at * 1000).toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'short', timeStyle: 'short' })) + '</small></span></label>';
      }).join('');
      var $m = $('<div class="modal fade" id="m4w-off-review" tabindex="-1" aria-labelledby="m4w-off-review-t"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        + '<div class="modal-header"><h2 class="modal-title fs-5" id="m4w-off-review-t"><i class="bi bi-send-check me-2 text-primary"></i>' + M4W.esc(t('review_title', { n: items.length })) + '</h2></div>'
        + '<div class="modal-body"><p class="small text-muted">' + M4W.esc(t('review_text')) + '</p><div class="m4w-off-review-list">' + rows + '</div></div>'
        + '<div class="modal-footer"><button type="button" class="btn btn-light" data-later>' + M4W.esc(t('review_later')) + '</button><button type="button" class="btn btn-primary" data-go><i class="bi bi-send me-1"></i>' + M4W.esc(t('review_send')) + '</button></div>'
        + '</div></div></div>').appendTo(document.body);
      var modal = bootstrap.Modal.getOrCreateInstance($m[0], { backdrop: 'static', keyboard: false });
      var answered = false;
      $m.on('click', '[data-go]', function () {
        answered = true;
        var p = Promise.resolve();
        $m.find('input[data-id]').each(function () {
          var it = items.filter(function (x) { return x.id === $(this).data('id'); }.bind(this))[0];
          if (!it) return;
          it.status = this.checked ? 'pending' : 'cancelled';
          if (!this.checked) it.done_at = now();
          p = p.then(function () { return storeItem(it); });
        });
        p.then(function () { modal.hide(); resolve(); });
      }).on('click', '[data-later]', function () { answered = true; modal.hide(); reject(new Error('later')); })
        .on('hidden.bs.modal', function () { $m.remove(); if (!answered) reject(new Error('later')); });
      modal.show();
    });
  }
  O.sendReviewed = function () { replay(); };

  // ------------------------------------------------------ the indicator
  function renderIndicator() {
    var $top = $('.m4w-topbar');
    if (!$top.length || !supported) return;
    var $w = $('#m4w-offline');
    if (!$w.length) {
      $w = $('<div class="dropdown m4w-offline-ind d-none" id="m4w-offline"><button class="btn btn-ghost btn-icon m4w-offline-btn" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">'
        + '<i class="bi bi-cloud-slash" aria-hidden="true"></i><span class="m4w-new-badge m4w-offline-count d-none"></span></button>'
        + '<div class="dropdown-menu dropdown-menu-end m4w-offline-menu"></div></div>');
      var $anchor = $top.find('.m4w-avatar-btn').closest('.dropdown');
      if ($anchor.length) $w.insertBefore($anchor); else $w.appendTo($top);
      $w.on('click', '[data-off-act]', function (e) {
        e.preventDefault();
        var act = $(this).data('off-act'), it = O.queue.filter(function (x) { return x.id === $(this).data('id'); }.bind(this))[0];
        if (act === 'retry' && it) { it.status = it.path === 'api/compose/send' ? 'review' : 'pending'; storeItem(it).then(replay); }
        if (act === 'remove' && it) { dropItem(it).then(changed); }
        if (act === 'clear') { var done = O.queue.filter(function (x) { return ['applied', 'ignored', 'cancelled'].indexOf(x.status) !== -1; }); seqEach(done, dropItem).then(changed); }
        if (act === 'send') replay();
      });
    }
    var pending = O.pending(), done = O.queue.filter(function (x) { return ['applied', 'ignored', 'cancelled'].indexOf(x.status) !== -1; });
    var show = O.allowed && (O.offline || pending > 0 || done.length > 0 || (O.locked && O.lockedCount));
    $w.toggleClass('d-none', !show);
    if (!show) return;
    var icon = O.offline ? 'cloud-slash' : (replaying ? 'cloud-arrow-up' : (pending ? 'cloud-arrow-up' : 'cloud-check'));
    $w.find('.m4w-offline-btn').toggleClass('is-offline', O.offline).attr('aria-label', O.offline ? t('title') : t('title_sync')).attr('title', O.offline ? t('title') : t('title_sync'))
      .find('.bi').attr('class', 'bi bi-' + icon);
    $w.find('.m4w-offline-count').text(pending).toggleClass('d-none', !pending);
    var head = O.offline ? (O.locked ? t('locked') : (O.key ? t('text') : t('nothing_kept'))) : (O.locked && O.lockedCount ? t('locked_online') : (replaying ? t('syncing') : (pending ? t('waiting') : t('done'))));
    var html = '<div class="m4w-offline-head"><b><i class="bi bi-' + icon + ' me-2"></i>' + M4W.esc(O.offline ? t('title') : t('title_sync')) + '</b><p>' + M4W.esc(head) + '</p></div>';
    var list = O.queue.slice().sort(function (a, b) { return b.seq - a.seq; }).slice(0, 30);
    if (list.length) {
      html += '<ul class="m4w-offline-list">' + list.map(function (it) {
        var st = it.status, txt = t('st_' + (st === 'applied' && it.result ? it.result : st));
        var ico = { pending: 'clock', review: 'eye', sending: 'arrow-repeat', applied: 'check-circle-fill', ignored: 'dash-circle', failed: 'exclamation-triangle-fill', cancelled: 'x-circle' }[st] || 'clock';
        return '<li class="st-' + st + '"><i class="bi bi-' + ico + '" aria-hidden="true"></i><span class="min-w-0"><span class="d-block text-truncate">' + M4W.esc(it.label) + '</span><small>' + M4W.esc(txt + (it.error ? ' — ' + it.error : '')) + '</small></span>'
          + (st === 'failed' && !O.offline ? '<button class="btn btn-ghost btn-sm" data-off-act="retry" data-id="' + M4W.esc(it.id) + '" title="' + M4W.esc(t('retry')) + '"><i class="bi bi-arrow-clockwise"></i></button>' : '')
          + (st === 'pending' || st === 'failed' || st === 'review' ? '<button class="btn btn-ghost btn-sm" data-off-act="remove" data-id="' + M4W.esc(it.id) + '" title="' + M4W.esc(t('remove')) + '"><i class="bi bi-x-lg"></i></button>' : '')
          + '</li>';
      }).join('') + '</ul>';
    }
    if (!O.offline && O.queue.some(function (x) { return x.status === 'review'; })) html += '<div class="m4w-offline-foot"><button class="btn btn-primary btn-sm w-100" data-off-act="send"><i class="bi bi-send me-1"></i>' + M4W.esc(t('review_open')) + '</button></div>';
    else if (done.length) html += '<div class="m4w-offline-foot"><button class="btn btn-light btn-sm w-100" data-off-act="clear">' + M4W.esc(t('clear_done')) + '</button></div>';
    $w.find('.m4w-offline-menu').html(html);
  }

  // --------------------------------------------- what is greyed offline
  var GREY = '#m4w-search-form, [data-action="adv-search"], a[href$="/files"], a[href*="/files/"], a[href$="/contacts"], a[href*="/contacts/"], a[href$="/settings"], a[href*="/settings/"], a[href*="/admin"], a[href$="/rules"], a[href*="/rules"], [data-folder-act="rules"], [data-cal-new], [data-cal-add], [data-cal-add-room], [data-cal-import], [data-c="attach"], [data-c="attach-files"], [data-c="image"], [data-c="schedule"]';
  function greyOut(on) {
    $(GREY).each(function () {
      var $e = $(this);
      if (on) {
        if ($e.is('.m4w-off-grey')) return;
        $e.addClass('m4w-off-grey').attr('aria-disabled', 'true').data('m4w-off-title', $e.attr('title') || '').attr('title', t('unavailable'));
      } else if ($e.is('.m4w-off-grey')) {
        $e.removeClass('m4w-off-grey').removeAttr('aria-disabled').attr('title', $e.data('m4w-off-title') || null);
      }
    });
  }
  $(document).on('click submit', '.m4w-off-grey', function (e) {
    if (!O.offline) return;
    e.preventDefault(); e.stopImmediatePropagation();
    M4W.toast(t('unavailable'), 'warning');
  });
  // New parts of the page (dialogs, menus) greyed too while offline.
  O.refreshGrey = function () { if (O.offline) greyOut(true); };

  // ------------------------------------------------------- signing out
  $(document).on('submit', 'form[action$="/logout"], form[action="logout"]', function (e) {
    var form = this;
    if ($(form).data('m4w-wiped')) return;
    e.preventDefault();
    var go = function () {
      O.wipe().then(function () {
        $(form).data('m4w-wiped', true);
        M4W.post('api/offline/forget', { device: DEVICE }, { silent: true, quiet: true, noOffline: true }).always(function () { form.submit(); });
      });
    };
    var n = O.pending();
    if (n && M4W.confirm) M4W.confirm(t('logout_title'), t('logout_pending', { n: n }), t('logout_anyway')).done(go);
    else go();
  });

  $(function () { O.start(); });
})(jQuery, window, document);
