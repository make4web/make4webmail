/*! Make4WebMail — service worker: push notifications for new mail, the app without a connection. */
'use strict';

/*
 * Offline: the app itself (pages of the mail and the calendar, scripts, styles, fonts, icons) is
 * kept here. Mail, calendar and pending work are NOT: the page keeps them encrypted (offline.js),
 * so that they become unreadable when the session ends. The kept pages hold no mail; they are
 * dropped all the same when the person signs out ("wipe").
 */
var STATIC = 'm4w-static-v1', PAGES = 'm4w-pages-v1';
var PAGE_NAMES = ['mail', 'calendar'];

self.addEventListener('install', function () { self.skipWaiting(); });
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
  return /^(assets\/|theme\.css|brand\/|app-icon\/|manifest\.webmanifest)/.test(path);
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
    data: { url: target(data.url) },
    // A reminder replaces the previous one but alerts again (sound, vibration of the system).
    renotify: !!data.renotify,
    silent: !!data.silent
  };
  if (vibrate.length) opts.vibrate = vibrate;
  var kind = data.kind === 'reminder' ? 'reminder' : 'new';
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    var mine = list.filter(function (c) { return c.url.indexOf(base) === 0; });
    // The app is in front (the server thought it closed: a missed check): no system
    // notification, the page checks at once and announces it itself, with the person's sound.
    if (data.kind && mine.some(function (c) { return c.focused; })) {
      mine.forEach(function (c) { c.postMessage({ type: 'm4w-push', kind: kind, id: data.id || 0 }); });
      return;
    }
    // Otherwise the system announces it; an open page in the background only updates (no second sound).
    return self.registration.showNotification(data.title || 'Make4WebMail', opts).then(function () {
      mine.forEach(function (c) { c.postMessage({ type: 'm4w-alert', kind: kind, id: data.id || 0 }); });
    });
  }));
});

// Tapping the notification opens (or focuses) the webmail on that message.
self.addEventListener('notificationclick', function (event) {
  event.notification.close();
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
