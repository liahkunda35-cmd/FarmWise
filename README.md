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

The first launch creates `storage/ifms.sqlite`.

## Database

SQLite is the default (no MySQL service required). To use MySQL, set environment variables before starting PHP:

- `IFMS_DB_DRIVER=mysql`
- `IFMS_DB_HOST`, `IFMS_DB_NAME`, `IFMS_DB_USER`, `IFMS_DB_PASS`

Then delete or ignore the SQLite file so schema + seed run against MySQL.

## Stack

PHP 8.2, PDO (SQLite/MySQL), session authentication, Chart.js, Leaflet/OpenStreetMap, Open-Meteo weather API.
