// CNAS service worker: shows emergency alert pushes (App\Notifications\AlertPushNotification) and opens the
// emergency map when one is tapped. Served from the site root so its scope covers every page.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let payload;
    try {
        payload = event.data?.json() ?? {};
    } catch {
        payload = { body: event.data?.text() ?? '' };
    }
    const { title = 'CNAS emergency alert', ...options } = payload;

    event.waitUntil(
        (async () => {
            await self.registration.showNotification(title, options);
            // Open CNAS pages check for the alert now instead of waiting for their next 15-second poll.
            const windows = await self.clients.matchAll({ type: 'window' });
            windows.forEach((client) => client.postMessage({ type: 'cnas:alert-push' }));
        })(),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url ?? '/', self.location.origin).href;

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            const open = windows.find((client) => new URL(client.url).origin === self.location.origin);
            if (open) {
                try {
                    await open.focus();
                    await open.navigate(url);
                    return;
                } catch {
                    // Not controlled by this worker yet: open a new window instead.
                }
            }
            await self.clients.openWindow(url);
        })(),
    );
});
