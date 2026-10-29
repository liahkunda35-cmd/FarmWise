# Frontend and backend separation

FarmWise still runs as one PHP application. The files are split so the interface can be copied without the database, permissions, or Neon credentials.

```text
frontend/
  bridge.php                 Presentation functions (fe_can, fe_csrf_field, …)
  config/api.js              Browser API client. No secrets.
  pages/                     Screen templates
  components/layouts/        App shell and print layout
  components/partials/       Chat, shared display helpers
  assets/css, js, images, vendor

backend/
  bootstrap.php              Env, session, database, routes
  config/                    permissions.php, modules, navigation, app config
  controllers/               HTTP actions and authorization
  services/                  Business rules, including PresentationData.php
  Auth.php, Database.php, Csrf.php, Router.php, …

public/                      Optional Apache document root (front controller only)
router.php                   Dev server: serves /assets from frontend/assets and blocks private paths
```

`config/permissions.php` is now `backend/config/permissions.php`. It is still the only permission matrix, and only the backend reads it.

## What the frontend may do

Templates call `fe_*` functions from `frontend/bridge.php`. Those functions do not open a database connection. The backend registers the real behaviour in `farmwise_bind_frontend()`.

Browser code calls `window.FarmWiseApi` in `frontend/config/api.js`.

- Leave `window.FARMWISE_API_BASE` empty to use this site (`window.IFMS_BASE` for a subdirectory install).
- Set `window.FARMWISE_API_BASE` to another origin before `api.js` loads.
- Set `window.FARMWISE_API_TOKEN` only when that other backend expects `Authorization: Bearer`. The PHP pages use the session cookie and do not put a token in JavaScript.

## What stays on the backend

Authorization, farm isolation, worker task limits, CSRF checks, password hashing, stock and payment rules, audit logging, and every SQL query. Hiding a button does not grant access. Controllers still call `Auth::authorize()` before an action.

The user row passed into templates has the password hash removed.

## Run this application

From the project folder, with PHP 8.2:

```bat
C:\xampp\php\php.exe -S 127.0.0.1:8081 router.php
```

`npm run dev` does the same. `router.php` maps `/assets/...` to `frontend/assets/...` and refuses `/backend/`, `/frontend/pages/`, `/frontend/components/`, `/frontend/bridge.php`, `.env`, and `storage/`.

`/frontend/config/api.js` is the one frontend PHP-adjacent file that is public, because the browser must download it. It contains no credentials.

Database settings stay in `.env` at the project root. Do not copy `.env` into `frontend/`.

## Reuse the frontend with another backend

Copy the `frontend/` directory. Do not copy `backend/`, `.env`, or `storage/`.

1. Serve `frontend/assets` at `/assets/` and `frontend/config/api.js` at `/frontend/config/api.js` (or change the script tag).
2. Set `window.FARMWISE_API_BASE` to the other backend.
3. Implement the contract below. Return only data the signed-in user is allowed to see. Enforce the same rules on GET and on every create, update, and delete.
4. The PHP templates are not a standalone SPA. A non-PHP host reuses the CSS, JavaScript, images, and API client, and renders its own HTML. A PHP host can render these templates after it defines the host helpers and binds `FrontendBridge`.

### Host helpers the templates call

`e`, `url`, `asset`, `brand_head`, `brand_logo`, `money`, `qty`, `dater`, `relative_time`, `weather_icon`, `user_first_name`, `password_input`, `flashes`, `user_safe_message`, `input`, `old`, `nav_items`, `current_path`, `option_label`, `safe_return_path`.

These format or read the current request. They must not query FarmWise tables themselves. Related-record labels and form choices come from `fe_relation_choices`, `fe_rel_label`, `fe_extra_related`, `fe_money_rows`, `fe_inventory_choices`, `fe_planting_cost`, `fe_animal_weights`, `fe_record_activity`, `fe_map_layers`, and `fe_user_directory_summary`. Another backend replaces `backend/services/PresentationData.php`.

### Browser API

JSON responses use `{ "ok": true, "data": { } }` or `{ "ok": false, "error": "message" }`.

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| POST | `/api/login` | None | Body `{ "email", "password" }`. Data: `token`, `token_type` (`Bearer`), `expires_in`, `user` (`id`, `name`, `email`, `role`, `farm_id`, `phone`). |
| GET | `/api/me` | Bearer | Current public user. |
| GET | `/api/dashboard` | Bearer | Dashboard payload for an authorised user. |
| GET | `/api/weather` | Bearer | Weather for the authorised farm. |
| GET | `/api/notifications` | Bearer | Notifications for the current user. |
| GET | `/api/geocode` | Bearer | Place search. |
| GET | `/api/module/{name}` | Bearer | List. Honour farm scope and, for tasks, the signed-in worker's assignments. |
| GET | `/api/module/{name}/{id}` | Bearer | One record, or 404/403. |
| POST | `/api/module/{name}` | Bearer | Create. 403 when the role cannot create. |
| POST | `/api/module/{name}/{id}` | Bearer | Update. Workers cannot retarget a task's farm, assignee, or links. |
| POST | `/assistant/chat` | Session + `X-CSRF-TOKEN` | JSON `{ message, history, mode, language, include_farm_context, _csrf }`. |
| GET | `/geocode?q=` | Session | Same-origin place search used by the map. |

### Session screens

Login, registration, password reset, dashboards, and module forms are HTML posts with a `_csrf` field from `fe_csrf_field()`. The session cookie is `HttpOnly`. A different backend can keep that design or replace the screens with its own HTML that still calls the JSON API.

Protected page paths include `/dashboard`, `/module/{name}`, `/reports`, `/users`, `/calendar`, `/weather`, `/notifications`, `/map`, `/search`, `/audit`, `/backup`, and `/integrations`. Each one must reject a role that `backend/config/permissions.php` does not allow, including a direct URL and a forged id.

## Remaining limits

The pages are still rendered by PHP. Copying `frontend/` does not produce a running site until a host implements the bridge and the endpoints above. There is no demo data in the client.

Module list screens still call the host helpers `input()` and `nav_items()` while they render. Those helpers are not FarmWise SQL, but they are part of the host contract.

Permissions, stock checks, payments, and farm isolation were not moved into JavaScript.
