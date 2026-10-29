# IFMS — Intelligent Farm Management System

A complete PHP farm-management platform for commercial estates: land, crops, irrigation, livestock, inventory, equipment, labour, pests, weather, harvest, sales, finance, procurement, storage, forecasts, alerts and reports.

## Run locally (Windows + XAMPP)

PHP is expected at `C:\xampp\php\php.exe`. From this folder:

```bat
start.bat
```

Or:

```bat
C:\xampp\php\php.exe -S localhost:8080 router.php
```

Open [http://localhost:8080](http://localhost:8080).

The interface lives in `frontend/` and the PHP application lives in `backend/`. See [docs/FRONTEND_SEPARATION.md](docs/FRONTEND_SEPARATION.md) for the layout, the API contract, and how to point this interface at another backend.

## Database

**Neon PostgreSQL (recommended / production):** copy `.env.example` to `.env` (never commit secrets) and set:

- `IFMS_DB_DRIVER=pgsql`
- `DATABASE_URL` and/or `DATABASE_URL_POOLED` (Neon connection string, `sslmode=require`)
- Optionally `DATABASE_URL_UNPOOLED` (direct compute). On older Windows/XAMPP `libpq` without SNI, the app prefers the **pooler** host and sends Neon’s `endpoint=` option automatically.
- Discrete vars also work: `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`, `PGSSLMODE`

Apply additive schema updates:

```bat
C:\xampp\php\php.exe scripts/migrate-schema.php
C:\xampp\php\php.exe scripts/probe-neon.php
```

**SQLite (local fallback only):** if no Postgres URL / `IFMS_DB_DRIVER=pgsql` is set, the first launch creates `storage/ifms.sqlite`.

**MySQL (optional):** `IFMS_DB_DRIVER=mysql` plus `IFMS_DB_HOST`, `IFMS_DB_NAME`, `IFMS_DB_USER`, `IFMS_DB_PASS`.

## Stack

PHP 8.2, PDO (SQLite/MySQL/Postgres), session authentication, Chart.js, Leaflet/OpenStreetMap, OpenWeather (Current + 5-day forecast).

## Weather (OpenWeather)

FarmWise loads current conditions and a multi-day forecast for the selected farm’s GPS coordinates using the **Free** OpenWeather endpoints:

- Current Weather: `https://api.openweathermap.org/data/2.5/weather`
- 5 Day / 3 Hour Forecast: `https://api.openweathermap.org/data/2.5/forecast`

These return temperature, conditions, humidity, wind, and rainfall when available. **One Call API 3.0/4.0 is not used** (separate paid subscription).

### Configure the API key

1. Create a key at [OpenWeather API keys](https://home.openweathermap.org/api_keys) (new keys can take up to ~2 hours to activate).
2. **Local development:** copy `.env.example` to `.env.local` (or `.env`) and set:

```env
OPENWEATHER_API_KEY=your_key_here
```

3. **Production:** set `OPENWEATHER_API_KEY` in the host environment (panel env vars, systemd, Docker, etc.). Do not commit real keys.
4. Keep the key **server-side only** — never use `VITE_` / `NEXT_PUBLIC_` prefixes or put it in JavaScript.

Also set each farm’s **latitude** and **longitude** (Farms module) so weather can be fetched for that location.

## Scheduled jobs

```bat
C:\xampp\php\php.exe scripts\cron.php
```

Runs alert sync, weather prefetch, and outbound email/SMS flush. Schedule every 15 minutes in production.

## IFMS status

See [docs/IFMS_IMPLEMENTATION_STATUS.md](docs/IFMS_IMPLEMENTATION_STATUS.md) for what is complete, what was connected in the latest pass, and what remains.
