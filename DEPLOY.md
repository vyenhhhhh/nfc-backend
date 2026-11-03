# Deploying the CSU CCIS OJT system

Two parts go online:

| Part | What it is | Where |
|---|---|---|
| **Backend** (`nfc-backend`) | Laravel API | a PHP server, in **Singapore** (same region as the Supabase database) |
| **Frontend** (`nfc-frontend`) | the website (React) | any static website host |

The database and the uploaded files stay on **Supabase**. The ACR122U helper (`tools/acr122u-helper`) runs on each kiosk PC.

> Pick **Singapore (ap-southeast-1)** for the backend server. Every tap asks the database about 14 things;
> in the same region each answer takes ~1 ms instead of tens of ms, so a tap takes well under a second.

---

## 1. Supabase (once)

1. **Storage → New bucket** → name `mytrack-private`, **Public bucket: OFF**. This is where requirement files,
   signed evaluation forms, school-event proofs and staff signatures go.
2. Keep the existing public bucket `mytrack` for profile photos and the blank requirement forms.
3. **Project Settings → Storage → S3 Connection**: note the endpoint and create S3 access keys.

## 2. Backend

Needs PHP 8.2+ with the extensions `pdo_pgsql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `zip`, and **OPcache on**.
Use the host's web server (Apache or Nginx + PHP-FPM) with the document root set to **`public/`**.
Do **not** use `php artisan serve` or `start-servers.bat` on the server (they are for the laptop only).

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env        # then fill it in (below)
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan uploads:make-private   # moves old requirement files / proofs / signatures to the private bucket
php artisan config:cache
php artisan route:cache
```

`.env` on the server (the rest as in `.env.example`):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.your-domain
FRONTEND_URL=https://your-domain
TRUSTED_PROXIES=*                  # most cloud hosts put a proxy in front; empty on a plain VPS
LOG_LEVEL=warning
UPLOADS_DISK=supabase
PRIVATE_UPLOADS_DISK=supabase_private
SUPABASE_PRIVATE_BUCKET=mytrack-private
KIOSK_KEY=                         # empty: kiosks use their own setup links
SECURE_CARDS=on                    # strict once every kiosk is an ACR122U or a phone
MAIL_MAILER=smtp                   # so imported interns get their temporary password
```

After **any** change to `.env` on the server: `php artisan config:cache` again.

Backups: Admin → Backups, or `php artisan mytrack:backup` (add it to the host's scheduler/cron for a daily copy).

## 3. Frontend

```bash
npm ci
npm run build          # the site is in dist/
```

Before building, set the production values (a `.env.production` file, or the host's build settings):

```dotenv
VITE_API_URL=https://api.your-domain
VITE_STORAGE_URL=https://<project-ref>.supabase.co/storage/v1/object/public/mytrack/
VITE_GOOGLE_CLIENT_ID=...
```

Do **not** set `VITE_API_PORTS` or `VITE_KIOSK_KEY`.

Upload `dist/` to the static host and turn on **"single-page app" / "rewrite all routes to /index.html"**
(otherwise opening `/admin` directly shows "not found"). The site must be **HTTPS**: the Android phone kiosk needs it.

In Google Cloud Console → Credentials → the OAuth client, add `https://your-domain` to the authorized JavaScript origins.

## 4. Kiosks

1. Admin → **Accounts → Kiosks**: for each kiosk, **Make a new key**, pick the card reader, **Copy link**,
   and open the link once on that kiosk device. Give HTE kiosks their HTE; leave the HTE empty only for CCIS kiosks.
2. **ACR122U PC**: in `tools/acr122u-helper/allowed-origins.txt` add `https://your-domain` on its own line,
   then restart `start-reader.bat`. The black window must say `kiosk pages allowed: ... https://your-domain`.
3. Tap a card and hold it until it **beeps**: the helper window shows `new code written`.
4. When Admin → Kiosks → Card alerts shows every card protected, set `SECURE_CARDS=strict` and `php artisan config:cache`.

## 5. Check before telling everyone

- [ ] `https://api.your-domain/up` answers (Laravel's health check)
- [ ] sign in as admin, coordinator, supervisor and intern; Google sign-in works
- [ ] Forgot password sends the recovery code email
- [ ] an intern uploads a requirement; the coordinator opens it (a link that stops working after ~10 minutes)
- [ ] opening a requirement file link in a private window **without** signing in fails
- [ ] a phone/laptop without the kiosk link shows "Kiosk not set up yet"
- [ ] a tap on each kiosk records the time and beeps
