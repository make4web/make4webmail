/*! Make4WebMail — service worker: push notifications for new mail. */
'use strict';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

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
  event.waitUntil(self.registration.showNotification(data.title || 'Make4WebMail', {
    body: data.body || '',
    tag: data.tag || 'm4w',
    icon: base + 'assets/img/icon-192.png',
    badge: base + 'assets/img/icon-192.png',
    data: { url: target(data.url) },
    renotify: false
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
