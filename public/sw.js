// public/sw.js
var CACHE_NAME = 'runit-v1';
// Handle window controls overlay for desktop PWA
self.addEventListener('message', function(event) {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
var OFFLINE_URL = '/';

// Install — cache the shell
self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      return cache.addAll([OFFLINE_URL, '/favicon.svg']);
    }).then(function() {
      return self.skipWaiting();
    })
  );
});

// Activate — clean old caches
self.addEventListener('activate', function(event) {
  event.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys.filter(function(k) { return k !== CACHE_NAME; })
            .map(function(k) { return caches.delete(k); })
      );
    }).then(function() {
      return clients.claim();
    })
  );
});

// Fetch — serve from cache when offline
self.addEventListener('fetch', function(event) {
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request).catch(function() {
        return caches.match(OFFLINE_URL);
      })
    );
  }
});

// Push — show notification
self.addEventListener('push', function(event) {
  var data = {};
  try { data = event.data.json(); } catch(e) {}

  var title   = data.title || 'RunIt';
  var options = {
    body:             data.body  || '',
    icon:             '/favicon.svg',
    badge:            '/favicon.svg',
    vibrate:          [200, 100, 200],
    requireInteraction: data.requireInteraction || false,
    data:             data.data || {},
    tag:              data.tag  || 'runit-' + Date.now(),
    renotify:         true,
    actions: data.data && data.data.order_id ? [
      { action: 'view',    title: '👁 View' },
      { action: 'dismiss', title: 'Dismiss'  },
    ] : [],
  };

  event.waitUntil(
    self.registration.showNotification(title, options)
  );
});

// Notification click
self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  if (event.action === 'dismiss') return;

  var url = '/';
  if (event.notification.data) {
    if (event.notification.data.url)      url = event.notification.data.url;
    else if (event.notification.data.order_id) url = '/orders/' + event.notification.data.order_id;
  }

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(list) {
      for (var i = 0; i < list.length; i++) {
        var client = list[i];
        if ('focus' in client) {
          client.focus();
          if ('navigate' in client) client.navigate(url);
          return;
        }
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});