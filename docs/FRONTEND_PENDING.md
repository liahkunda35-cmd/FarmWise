# Front-end pending (backend / config needed)

Items that need PHP routes, permissions, schema, or module config changes outside the allowed front-end surface.

## Phase 1–7 (current)

1. **Farm Map nav key `farm_map`** — Layout falls back to `Auth::can('fields', 'view')`. Prefer a real `farm_map` permission (or `can_see_nav` alias).

2. **Global search route** — Top bar posts to `GET /search` and `views/search.php` exists, but `Router.php` has no `search` branch → **404** until a protected route + controller action are added.

3. **Dedicated boundary column** — Field polygons are stored as a final `[[GEOJSON]]{...}` line inside `boundary_notes`. A dedicated `boundary_geojson` column would be cleaner.

4. **Warehouses & water sources on map** — No latitude/longitude columns; they cannot be drawn as layers. Add coords (or link to a field/farm) to plot them.

5. **List filters for related tabs** — “Open filtered list” links go to the module index; server-side `?field_id=` / `?planting_id=` filtering is not implemented in `ResourceController`, so the list does not narrow until that exists.

6. **Users list filters** — Role/status/q filters are applied in the view over the already-loaded `$rows`. Server-side pagination/filtering on `users` would be better for large accounts.

7. **Audit filters** — Same: client/view filtering over `$rows` until the audit controller accepts query params.

8. **Activity tab** — Needs `audit_logs.record_id` + `module` populated by the backend for every write; otherwise the tab stays empty.

9. **Money tab / “From:” badges** — Depend on `transactions.related_module` and `related_id` being set by finance hooks. Manual rows without those columns show a Manual badge when description is shown.

10. **Dependent selects** — Planting←Field works when options carry `data-parent`. Irrigation→Field and Animal→Breed prefill need those selects present on the same form; if a module form omits the target field, prefill is skipped (logged here rather than inventing fields).

11. **Leaflet.draw GeometryUtil** — Area uses a geodesic fallback when `L.GeometryUtil` is absent (draw plugin does not always ship it). Accuracy is good enough for ha estimates; optional vendor of leaflet-geometryutil later.

12. **Readable “no access” page** — Controllers still call `abort(403, …)`. A nicer view needs a change in the abort/error renderer under `app/`.
