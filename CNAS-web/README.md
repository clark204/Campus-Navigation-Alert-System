# CNAS (TCC NAV)

## Emergency alerts need a queue worker

Browser push notifications for emergency alerts (sent, all clear, false alarm) are sent by a queued job
(`App\Jobs\SendAlertPush`) on the `database` queue (`QUEUE_CONNECTION=database`). **A queue worker must be running**,
or the alert still shows on open pages but no phone gets a notification:

```bash
php artisan queue:work --tries=3
```

- `composer run dev` already starts a queue listener (with the server and Vite). If you start `php artisan serve` on
  its own, start the worker in another terminal too.
- In production, keep `php artisan queue:work` running under a process manager (e.g. Supervisor) and restart it after
  each deploy (`php artisan queue:restart`).
- **Windows / Laragon:** PHP needs to find `openssl.cnf` to encrypt push messages (and to generate VAPID keys). Set it in
  the terminal before starting the worker, e.g. in PowerShell:
  `$env:OPENSSL_CONF = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\extras\ssl\openssl.cnf"`
- VAPID keys live in `.env` (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`). Generate them once with
  `php artisan webpush:vapid` and never change them, or every device has to turn alerts on again.
- Push and the service worker need HTTPS (`localhost` is fine on the same computer; use a tunnel such as ngrok to test
  on a phone). iPhones only get push after "Add to Home Screen" (iOS 16.4+).
- The scheduler (`php artisan schedule:work`, or cron in production) deletes "I'm safe" answers 30 days after their
  alert ends.
