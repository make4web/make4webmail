/*! Make4WebMail — service worker: push notifications for new mail, the app without a connection. */
'use strict';

/*
 * Offline: the app itself (pages of the mail and the calendar, scripts, styles, fonts, icons) is
 * kept here. Mail, calendar and pending work are NOT: the page keeps them encrypted (offline.js),
 * so that they become unreadable when the session ends. The kept pages hold no mail; they are
 * dropped all the same when the person signs out ("wipe").
 */
var STATIC = 'm4w-static-v2', PAGES = 'm4w-pages-v1'; // v2: drops manifests kept by older versions
var PAGE_NAMES = ['mail', 'calendar'];

/** The version of the app that installed this service worker (sw.js?v=…). */
var VERSION = new URL(self.location.href).searchParams.get('v') || '';

self.addEventListener('install', function () { self.skipWaiting(); });
// Asked by the settings page: which version shows the notifications on this device.
self.addEventListener('message', function (event) {
  if (event.data && event.data.type === 'm4w-version' && event.ports && event.ports[0]) event.ports[0].postMessage({ version: VERSION });
});
self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return /^m4w-/.test(k) && k !== STATIC && k !== PAGES; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

function rel(url) {
  var scope = self.registration.scope;
  return url.indexOf(scope) === 0 ? url.slice(scope.length) : null;
}
function isStatic(path) {
  return /^(assets\/|theme\.css|brand\/|app-icon\/)/.test(path);
}
function pageName(path) {
  var p = path.split('?')[0].split('#')[0].replace(/\/$/, '');
  return PAGE_NAMES.indexOf(p) !== -1 ? p : (p === '' ? 'mail' : null);
}

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;
  var path = rel(req.url);
  if (path === null) return;
  // Files of the app: from here, fetched once (their address changes with each version).
  if (isStatic(path)) {
    event.respondWith(caches.open(STATIC).then(function (c) {
      return c.match(req).then(function (hit) {
        return hit || fetch(req).then(function (res) {
          if (res.ok && res.type === 'basic') c.put(req, res.clone());
          return res;
        });
      });
    }));
    return;
  }
  // The manifest keeps its address while the name or the icons change: always asked to the
  // server (a kept copy made the installed app ask again and again to change its icon), the
  // kept copy only without a connection.
  if (/^manifest\.webmanifest/.test(path)) {
    event.respondWith(fetch(req).then(function (res) {
      if (res.ok && res.type === 'basic') { var copy = res.clone(); caches.open(STATIC).then(function (c) { c.put(req, copy); }); }
      return res;
    }).catch(function () { return caches.open(STATIC).then(function (c) { return c.match(req); }); }));
    return;
  }
  // Pages: the network first (always the latest), the kept copy without a connection.
  if (req.mode === 'navigate') {
    var name = pageName(path);
    event.respondWith(fetch(req).then(function (res) {
      if (name && res.ok && !res.redirected && res.type === 'basic') {
        var copy = res.clone();
        caches.open(PAGES).then(function (c) { c.put(self.registration.scope + name, copy); });
      }
      return res;
    }).catch(function () {
      return caches.open(PAGES).then(function (c) {
        return (name ? c.match(self.registration.scope + name) : Promise.resolve(null)).then(function (hit) {
          if (hit) return hit;
          // A page that needs the server (settings, files…): said plainly, with the way back.
          return c.match(self.registration.scope + 'mail').then(function (mail) {
            return new Response('<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hors ligne</title>'
              + '<body style="font-family:system-ui,sans-serif;padding:2rem 1rem;max-width:34rem;margin:auto;color:#1f2937;line-height:1.5">'
              + '<h1 style="font-size:1.4rem">Hors ligne</h1><p>Cette page a besoin de la connexion. La messagerie et l\'agenda, eux, restent utilisables : '
              + 'vos messages partiront et vos changements seront appliqués dès le retour de la connexion.</p>'
              + (mail ? '<p><a href="' + self.registration.scope + 'mail" style="display:inline-block;padding:.6rem 1rem;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none">Ouvrir la messagerie</a></p>' : '')
              + '</body></html>', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
          });
        });
      });
    }));
  }
  // Everything else (the API) goes to the network: the page keeps what it needs, encrypted.
});

// From the page: keep the app's pages and their files ready (signed in, online), or drop them.
self.addEventListener('message', function (event) {
  var msg = event.data || {};
  if (msg.type === 'wipe') {
    event.waitUntil(caches.delete(PAGES));
    return;
  }
  if (msg.type !== 'warm') return;
  var scope = self.registration.scope;
  event.waitUntil(caches.open(PAGES).then(function (pages) {
    return Promise.all(PAGE_NAMES.map(function (name) {
      return fetch(scope + name, { credentials: 'same-origin' }).then(function (res) {
        if (!res.ok || res.redirected) return null;
        return res.clone().text().then(function (html) {
          var urls = [], re = /(?:src|href)="([^"]+)"/g, m;
          while ((m = re.exec(html))) {
            var u = m[1].replace(/&amp;/g, '&'), abs;
            try { abs = new URL(u, scope).href; } catch (e) { continue; }
            var p = rel(abs);
            if (p !== null && isStatic(p) && urls.indexOf(abs) === -1) urls.push(abs);
          }
          return pages.put(scope + name, res).then(function () {
            return caches.open(STATIC).then(function (st) {
              return Promise.all(urls.map(function (u) {
                return st.match(u).then(function (hit) {
                  return hit || fetch(u, { credentials: 'same-origin' }).then(function (r) { if (r.ok) return st.put(u, r); }).catch(function () {});
                });
              }));
            });
          });
        });
      }).catch(function () {});
    }));
  }));
});

// Links in a notification stay inside the webmail.
function target(rel) {
  var scope = self.registration.scope;
  try {
    var u = new URL(rel || 'mail', scope);
    if (u.href.indexOf(scope) === 0) return u.href;
  } catch (e) {}
  return scope + 'mail';
}

self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: event.data ? event.data.text() : '' }; }
  var base = self.registration.scope;
  // Badge of the installed app (desktop, Android, iOS 16.4+): the unread total, even app closed.
  if (typeof data.count === 'number' && self.navigator && self.navigator.setAppBadge) {
    try { data.count ? self.navigator.setAppBadge(data.count) : self.navigator.clearAppBadge(); } catch (e) {}
  }
  var vibrate = Array.isArray(data.vibrate) ? data.vibrate.filter(function (n) { return typeof n === 'number' && n >= 0 && n <= 2000; }).slice(0, 10) : [];
  var opts = {
    body: data.body || '',
    tag: data.tag || 'm4w',
    icon: base + (data.icon && /^[a-z0-9\/._-]+$/i.test(data.icon) ? data.icon : 'assets/img/icon-192.png'),
    badge: base + 'assets/img/icon-192.png',
    data: { url: target(data.url), test: data.test || '' },
    // A reminder replaces the previous one but alerts again (sound, vibration of the system).
    renotify: !!data.renotify,
    silent: !!data.silent
  };
  if (vibrate.length) opts.vibrate = vibrate;
  var kind = data.kind === 'reminder' ? 'reminder' : 'new';
  // Test of every device: each step said back to the server (received, shown), with the app's
  // state on this device at that moment (no window, in the background, visible, in front).
  if (data.test) {
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      var mine = list.filter(function (c) { return c.url.indexOf(base) === 0; });
      var state = !mine.length ? 'off' : (mine.some(function (c) { return c.focused; }) ? 'focus' : (mine.some(function (c) { return c.visibilityState === 'visible'; }) ? 'visible' : 'background'));
      return testAck(data.test, 'received', state).then(function () {
        return self.registration.showNotification(data.title || 'Make4WebMail', opts);
      }).then(function () { return testAck(data.test, 'shown', state); });
    }).catch(function () {}));
    return;
  }
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    var mine = list.filter(function (c) { return c.url.indexOf(base) === 0; });
    // The app is in front (the server thought it closed: a missed check): no system
    // notification, the page checks at once and announces it itself, with the person's sound.
    if (data.kind && mine.some(function (c) { return c.focused; })) {
      mine.forEach(function (c) { c.postMessage({ type: 'm4w-push', kind: kind, id: data.id || 0 }); });
      return;
    }
    // Otherwise the system shows it. An open page in the background (alive a while after the app
    // left the screen) first plays the app's own sound, the one chosen in the settings (on a phone
    // the system's sound cannot be chosen per app): the notification is then silent. A page asleep
    // or closed does not answer in time: the system's sound then.
    return (data.kind && !data.silent ? playThere(mine, kind, data.id || 0) : Promise.resolve(false)).then(function (played) {
      if (played) opts.silent = true;
      return self.registration.showNotification(data.title || 'Make4WebMail', opts);
    }).then(function () {
      mine.forEach(function (c) { c.postMessage({ type: 'm4w-alert', kind: kind, id: data.id || 0 }); });
    });
  }));
});

/** Asks the open pages to play the app's sound; true if one did within a second and a half. */
function playThere(list, kind, id) {
  if (!list.length || typeof MessageChannel === 'undefined') return Promise.resolve(false);
  return new Promise(function (resolve) {
    var done = false, left = list.length;
    var finish = function (v) { if (!done) { done = true; resolve(v); } };
    setTimeout(function () { finish(false); }, 1500);
    list.forEach(function (c) {
      var ch = new MessageChannel();
      ch.port1.onmessage = function (e) { if (e.data && e.data.played) finish(true); else if (--left === 0) finish(false); };
      try { c.postMessage({ type: 'm4w-play', kind: kind, id: id }, [ch.port2]); } catch (e) { if (--left === 0) finish(false); }
    });
  });
}

// A step of the test of every device, said to the server with this device's subscription hash.
function testAck(test, stage, state) {
  return self.registration.pushManager.getSubscription().then(function (sub) {
    if (!sub || !self.crypto || !crypto.subtle) return;
    return crypto.subtle.digest('SHA-256', new TextEncoder().encode(sub.endpoint)).then(function (h) {
      var hex = Array.prototype.map.call(new Uint8Array(h), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
      return fetch(self.registration.scope + 'api/push/ack', { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ test: String(test), device: hex, stage: stage, state: state || '' }) });
    });
  }).catch(function () {});
}

// Tapping the notification opens (or focuses) the webmail on that message.
self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  if (event.notification.data && event.notification.data.test) event.waitUntil(testAck(event.notification.data.test, 'clicked', ''));
  var url = (event.notification.data && event.notification.data.url) || self.registration.scope + 'mail';
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      var c = list[i];
      if (c.url.indexOf(self.registration.scope) === 0 && 'focus' in c) {
        return c.focus().then(function (w) { return w && 'navigate' in w ? w.navigate(url) : w; });
      }
    }
    return self.clients.openWindow(url);
  }));
});

// The browser rotated the subscription: hand the new one to the server.
self.addEventListener('pushsubscriptionchange', function (event) {
  event.waitUntil(fetch(self.registration.scope + 'api/push/key', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (k) {
    if (!k.key) return;
    var raw = atob(k.key.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((k.key.length + 3) % 4));
    var key = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) key[i] = raw.charCodeAt(i);
    return self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }).then(function (sub) {
      return fetch(self.registration.scope + 'api/push/renew', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': k.csrf },
        body: JSON.stringify({ old: event.oldSubscription ? event.oldSubscription.endpoint : '', subscription: sub.toJSON() })
      });
    });
  }));
});
