# IFMS / FarmWise — Implementation status

Honest status after the March 2026 completion pass. This app is a **working PHP farm platform** with ~49 CRUD modules, not a static demo. Cross-module automation was the main gap; this pass closed the highest-impact connections.

## 1. Already working (before this pass)

- Auth: register/login/logout, password reset, roles (admin/owner/manager/agronomist/worker/accountant), CSRF, password hashing, farm join codes
- Config-driven CRUD for farms, fields, crops, irrigation, livestock, inventory, equipment, labour, pests, harvests, commerce, finance
- Farm-scoped access control on web modules
- Dashboard KPIs from live DB queries
- OpenWeather integration (`OPENWEATHER_API_KEY`) with cache in `weather_logs`
- Gemini AI assistant (`GEMINI_API_KEY`) with authorized farm context
- Reports + Chart.js + CSV/PDF export
- Notifications (`NotificationService::syncAlerts`) on dashboard traffic
- Attachments, documents (invoice/PO/quote PDF/email), calendar, map
- Neon Postgres / SQLite / MySQL via env

## 2. Implemented / repaired in this pass

| Area | Change |
|------|--------|
| **Operation hooks** | New `app/services/OperationHooks.php` — harvest→storage lots, dispatch stock deduct, input usage→inventory out, PO receive→stock in, idempotent finance posts, equipment hours roll-up |
| **Resource save** | DB transactions around save + hooks; stock/dispatch pre-validation |
| **API writes** | `ApiController` validates fields, audits, runs `OperationHooks` (parity with web) |
| **Schema** | `transactions.related_id`; optional `warehouse_id` / `inventory_item_id` / quantities on harvests, fertilizer, sprays, treatments, feed, POs |
| **PO finance** | Expense posts when status becomes **received** (not on draft create) |
| **Payroll finance** | Expense posts when status becomes **paid** |
| **List filters** | `?planting_id=`, `?field_id=`, other `*_id` query filters work for workflow chips |
| **Global search** | `GET /search` route + `WorkspaceController::search` |
| **Cron** | `scripts/cron.php` — alerts, weather prefetch, message flush |
| **Bootstrap** | Additive migration flag `.schema_mig_ops1` |

## 3. Database migrations applied

Additive via `Schema::migrate()` (runs when `storage/.schema_mig_ops1` is missing):

- `transactions.related_id`
- `harvests.warehouse_id`
- `fertilizer_applications.inventory_item_id`
- `pest_treatments.inventory_item_id` + `quantity`
- `livestock_feed.inventory_item_id`
- `spray_schedules.inventory_item_id` + `quantity`
- `purchase_orders.inventory_item_id`
- Indexes on `transactions.reference`, `stock_movements.reference`

No destructive schema changes. Delete `storage/.schema_mig_ops1` to re-run additive migrations after pulling updates.

## 4. Workflows tested / how to verify

| Workflow | How to verify |
|----------|----------------|
| Harvest → storage | Create completed harvest → Storage Lots should show a lot (auto warehouse if needed) |
| Dispatch | Create dispatch → lot quantity decreases; oversell blocked |
| Fertilizer + inventory | Link inventory item + quantity on application → stock movement `out` |
| PO receive | Set PO status to Received + inventory item → stock in + expense once |
| Search | Header search → `/search?q=…` |
| Weather | Dashboard/Weather with farm GPS + `OPENWEATHER_API_KEY` |
| Cron | `php scripts/cron.php` from CLI |

Automated PHPUnit suite is **not** present; verification is manual against Neon.

## 5. Environment variables to configure

| Variable | Purpose |
|----------|---------|
| `DATABASE_URL` / `DATABASE_URL_POOLED` / `DATABASE_URL_UNPOOLED` | Neon Postgres |
| `OPENWEATHER_API_KEY` | Live weather |
| `GEMINI_API_KEY` (+ optional `GEMINI_MODEL`) | AI assistant |
| SMTP / SMS settings | Via Integrations UI (`settings` table), not only env |

See `.env.example`.

## 6. Still incomplete / blocked

| Feature | Reason |
|---------|--------|
| Full double-entry accounting / accrual sales revenue | Only cash income on payments + expense hooks; no GL chart of accounts |
| Attendance → auto payroll calculation | Payroll remains manual entry |
| Budget vs actual report panel | Data model exists; dedicated report UI not built |
| External market price feeds | Manual `market_prices` only |
| pg_dump-grade Neon backups | JSON backup/restore exists but is not production DR |
| Sensor soil moisture / pump control | No hardware integration |
| 2FA / SSO | Not implemented |
| Duplicate finance on **delete** reversing | Deletes do not reverse stock/finance (by design for now) |
| Mobile offline | Not in scope |

## 7. How to run

**App (XAMPP):**

```bat
C:\xampp\php\php.exe -S localhost:8080 router.php
```

Or `start.bat`.

**Cron (every 15 minutes):**

```bat
C:\xampp\php\php.exe C:\Users\Liah\Desktop\FarmMS\scripts\cron.php
```

Linux:

```
*/15 * * * * /usr/bin/php /path/to/FarmMS/scripts/cron.php >> /path/to/FarmMS/storage/cron.log 2>&1
```

**Inventory automation tip:** Select an inventory item on fertilizer/spray/feed/treatment/PO forms so stock moves automatically. Without that link, records still save but inventory is unchanged (manual stock movements still work).
