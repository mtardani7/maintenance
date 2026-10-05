self.addEventListener('push', (event) => {
  let payload = {};
  try { payload = event.data ? event.data.json() : {}; } catch { payload = { body: event.data ? event.data.text() : '' }; }

  const scopePath = new URL(self.registration.scope).pathname.replace(/\/$/, '');
  const title = payload.title || 'MIRA';
  const options = {
    body: payload.body || 'You have a new maintenance notification.',
    icon: payload.icon || `${scopePath}/pwa-icon-192.png?v=2`,
    badge: payload.badge || `${scopePath}/pwa-icon-192.png?v=2`,
    tag: payload.tag || `maintenance-${payload.id || Date.now()}`,
    data: {
      url: payload.url || (payload.relatedTicketId ? `${scopePath}/tickets/${payload.relatedTicketId}` : `${scopePath}/notifications`),
      notificationId: payload.notificationId || payload.id,
    },
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const scopePath = new URL(self.registration.scope).pathname.replace(/\/$/, '');
  const target = new URL(event.notification.data?.url || `${scopePath}/notifications`, self.location.origin).href;
  event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
    const existing = windows.find((window) => window.url.startsWith(self.location.origin));
    if (existing && event.notification.data?.notificationId) {
      return existing.focus().then(() => existing.postMessage({ type: 'maintenance:notification-click', notificationId: event.notification.data.notificationId, url: target }));
    }
    if (existing) { existing.focus(); return existing.navigate(target); }
    return clients.openWindow(target);
  }));
});
