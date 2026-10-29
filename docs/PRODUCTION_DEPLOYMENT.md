# FarmWise production deployment

Do not use PHP’s built-in development server (`php -S`) in production. Use Apache or nginx with PHP-FPM (or XAMPP Apache with `pdo_pgsql` enabled).

## 1. Host requirements

- PHP **8.2+**
- Extensions: `pdo_pgsql`, `pgsql`, `curl`, `openssl`, `mbstring`, `json`, `fileinfo`
- Writable directories: `storage/`, `storage/uploads/`, `storage/logs/` (if used)
- Outbound HTTPS to Neon, Nexus, OpenWeather, Gemini

Verify locally:

```bat
C:\xampp\php\php.exe -m | findstr /i "pdo_pgsql curl openssl"
```

## 2. Environment

1. Copy `.env.example` to **`.env.local`** (preferred) or `.env`.
2. Never commit `.env`, `.env.local`, or API keys.
3. Set at minimum:

| Variable | Purpose |
|----------|---------|
| `IFMS_DB_DRIVER=pgsql` | Force Postgres |
| `DATABASE_URL_POOLED` / `DATABASE_URL` | Neon connection strings |
| `PGHOST`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`, `PGSSLMODE=require` | Fallback Neon params |
| `NEXUS_API_KEY` | NexusProMail (or WRNexus) API key from dashboard |
| `NEXUS_API_URL` | Default `https://app.nexuspromail.com` |
| `NEXUS_FROM_EMAIL` | **Required** verified from-address for NexusProMail |
| `NEXUS_FROM_NAME` | Display name (e.g. FarmWise) |
| `NEXUS_SENDER_KEY` | Required only if `NEXUS_PROVIDER=wrnexus` |
| `IFMS_SESSION_SECURE=1` | Force Secure session cookies (or terminate TLS so HTTPS is detected) |
| `IFMS_SSL_CAINFO` | Optional path to CA bundle (Sophos: append inspection CA to `storage/certs/cacert-with-local.pem`) |
| `OPENWEATHER_API_KEY` / `GEMINI_API_KEY` | Optional integrations |

## 3. Web server

Serve over **HTTPS**. Session cookies become `Secure` when HTTPS or `IFMS_SESSION_SECURE=1` is set.

### Preferred document root

Point the site at the `public/` directory only. That folder contains `public/index.php`, which loads the application. PHP code, `config/`, `.env`, and `storage/` (including `storage/jwt.secret` and `storage/backups/`) stay outside the document root, so the web server cannot download them.

Static files live in `assets/` next to `app/`. Map that folder to the `/assets/` URL. PHP still reads uploads and backup files from `storage/` on disk; do not expose `storage/` as a URL.

Apache example:

```apache
DocumentRoot "/var/www/farmwise/public"
Alias /assets "/var/www/farmwise/assets"
<Directory "/var/www/farmwise">
    Require all denied
</Directory>
<Directory "/var/www/farmwise/public">
    AllowOverride All
    Require all granted
</Directory>
<Directory "/var/www/farmwise/assets">
    Require all granted
</Directory>
```

nginx example:

```nginx
root /var/www/farmwise/public;
location /assets/ {
    alias /var/www/farmwise/assets/;
}
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### If the document root is still the project folder

The root `.htaccess` refuses `.env`, `storage/`, `app/`, `config/`, `views/`, `scripts/`, backups, certificate files, and the JWT secret before Apache can send them. `router.php` does the same for PHP's built-in server. This is a safety net, not a substitute for a `public/` document root.

Do not remove those deny rules. Uploads and backups remain available through signed-in PHP actions (`/module/.../file/...` and `/backup/download`).

## 4. Database

- Point at Neon; do **not** reset production data for testing.
- Schema markers under `storage/.schema_mig_*` are applied by `Schema::migrate()` on bootstrap.
- Optional: `C:\xampp\php\php.exe scripts\migrate-schema.php`
- Backups: use **Neon console** snapshots / PITR. In-app JSON backup is not full DR.

## 5. Uploads

- Files land in `storage/uploads/{farm_id}/{module}/{record_id}/`
- Allowed: JPG, PNG, GIF, WEBP, PDF; max **5 MB**
- Ensure the web user can create directories under `storage/uploads`

## 6. Cron

Every 15 minutes:

```
*/15 * * * * /usr/bin/php /path/to/FarmMS/scripts/cron.php >> /path/to/FarmMS/storage/cron.log 2>&1
```

Windows Task Scheduler can run `C:\xampp\php\php.exe C:\path\to\FarmMS\scripts\cron.php`.

## 7. Transactional email checklist

1. Create/copy a valid API key in the Nexus dashboard (Premium/Enterprise or sandbox with API access).
2. Set `NEXUS_API_KEY` and `NEXUS_FROM_EMAIL` in `.env.local` only.
3. Restart PHP / clear opcache if needed.
4. Sign in as admin → Integrations → confirm checklist shows key/from as **set** (never paste the raw key in the UI).
5. Send **test email** to your inbox.
6. CLI probe: `php scripts/probe-nexus.php` (must not print the raw key).
7. Then exercise register → approve → activate → forgot-password and confirm inbox delivery.

If HTTPS fails with a certificate issuer error behind Sophos: export the Sophos SSL CA PEM, append to `storage/certs/cacert-with-local.pem`, or set `IFMS_SSL_CAINFO`. **Do not disable SSL verification.**

## 8. Smoke after deploy

1. `/login` for all six seed roles (or your production users).
2. Create a field; refresh; confirm it remains.
3. Open `/reports?type=financial` (cash-basis labels).
4. Admin Integrations test email (when credentials are valid).
5. Confirm `storage/uploads` accepts a small PNG on an allowed record.

## 9. What this environment has verified

Local XAMPP PHP 8.2.12 + `pdo_pgsql` + Neon connectivity and Phase 1–6 automated/browser suites. A separate live production host is **not** claimed verified until you deploy there and re-run the smoke list above.
