# CNAS-web

The Laravel website and API of CNAS (TCC NAV). What the system is, the architecture, setup, testing and deployment
are in the [main README](../README.md). This page only has notes specific to running the website.

## Emergency alerts need a queue worker

Push notifications for emergency alerts (sent, all clear, false alarm), admin reports and emails (verification and
password codes) are queued on the `database` queue (`QUEUE_CONNECTION=database`). **A queue worker must be running**, or an
alert still shows on open pages but no phone or browser gets a notification:

```bash
php artisan queue:work --tries=3
```

- `composer run dev` already starts a queue listener (with the server and Vite). If you start `php artisan serve` on
  its own, start the worker in another terminal too.
- In production, keep `php artisan queue:work` running under a process manager (e.g. Supervisor) and restart it after
  each deploy (`php artisan queue:restart`).

## Push keys and HTTPS

- VAPID keys live in `.env` (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`). Generate them once with
  `php artisan webpush:vapid` and never change them, or every device has to turn alerts on again.
- **Windows / Laragon:** PHP needs to find `openssl.cnf` to encrypt push messages (and to generate VAPID keys). Set it
  in the terminal before starting the worker, e.g. in PowerShell:
  `$env:OPENSSL_CONF = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\extras\ssl\openssl.cnf"`
- Push and the service worker need HTTPS (`localhost` is fine on the same computer; use a tunnel such as ngrok to
  test on a phone). iPhones only get web push after "Add to Home Screen" (iOS 16.4+).
- `APP_URL` must be the public https address in production: push links, icons and the printed QR codes use it.

## Scheduler

The scheduler (`php artisan schedule:work` locally, a cron entry for `schedule:run` in production) deletes "I'm safe"
answers 30 days after their alert ends, sign-in history after 90 days, and expired registration drafts and email
codes.

## Feature notes

Each feature's decisions and code locations are in [`docs/`](docs). The list is in the
[main README](../README.md#documentation).
