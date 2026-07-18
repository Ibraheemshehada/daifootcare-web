# DiaFootCare — Web Dashboard & Sync API

Laravel 12 + Vue 3 backend and clinical dashboard for the **DiaFootCare** Flutter app
(`Ibraheemshehada/diafootcare_new`). Built from `DaiFootCare_Web_Master_Brief.md`.

The mobile app is offline-first and complete — see `OFFLINE_MODE_STATUS.md` in the app
repo. This project adds the server side: accounts, device tracking, and the idempotent
sync endpoint that receives locally-captured scans once a device regains connectivity.

---

## Stack

| Layer | Choice | Note |
|---|---|---|
| Backend | **Laravel 12** | The brief specified Laravel 11. Composer **refused to install it** — every 11.x release (v11.31.0–v11.55.0) carries security advisories including reflected XSS. Laravel 12 is the supported line and is API-compatible for everything here. |
| Auth | Laravel Sanctum 4 | Token-based, correct for a mobile client |
| Frontend | Vue 3.5 (Composition API) | |
| Build | Vite 7 + laravel-vite-plugin 2 | |
| CSS | Tailwind v4 | Supplied by the `@nuxt/ui` Vite plugin — do **not** add `@tailwindcss/vite` separately, it double-processes the stylesheet |
| UI kit | @nuxt/ui v4 | |
| Animation | motion-v | The Vue port of Framer Motion (`framer-motion` itself is React-only) |
| State / Router / i18n | Pinia 4, vue-router 5, vue-i18n 11 | Newer majors than the brief pinned; verified working |

## Getting started

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan db:seed --class=DemoSeeder
npm run build          # or: npm run dev
php artisan serve
```

Requires the **`pdo_sqlite`** and **`sqlite3`** PHP extensions (uncomment them in `php.ini`).

Demo accounts from `DemoSeeder` (**development only — never seed these in production**):

| Email | Role | Password |
|---|---|---|
| `doctor@daifootcare.test` | doctor | `password` |
| `patient@daifootcare.test` | patient | `password` |

## API (v1)

All routes are prefixed `/api/v1`. Authenticated routes need `Authorization: Bearer <token>`.

| Method | Path | Access | Purpose |
|---|---|---|---|
| POST | `/auth/register` | public | Always creates a **patient** — `role` is not mass-assignable |
| POST | `/auth/login` | public | Returns a Sanctum token |
| POST | `/auth/logout` | auth | Revokes **only the calling token** |
| GET | `/auth/me` | auth | Current user |
| POST | `/devices/register` | auth | Idempotent on `device_uuid`; `409` if claimed by another account |
| PATCH | `/devices/{uuid}/mode` | auth | Switch online/offline, confirm model download |
| GET | `/devices` | auth | Own devices; clinicians see all |
| POST | `/wound-scans/sync` | auth | **Idempotent batch upsert** (see below) |
| GET | `/wound-scans` | auth | Own scans; clinicians see all |
| GET | `/patients` | clinician | Patient list |
| GET | `/dashboard/stats` | clinician | Aggregate counts |

`register`/`login` are throttled to 6/min; `sync` to 30/min.

### The sync contract

```jsonc
POST /api/v1/wound-scans/sync
{
  "device_uuid": "…",
  "batch_uuid":  "…",          // optional, for tracing a retried batch
  "records": [                  // max 50 per request
    {
      "local_uuid":  "…",       // UUID v4 generated ON DEVICE AT CAPTURE TIME
      "captured_at": "2026-07-17T09:00:00Z",
      "length_cm": 3.2, "width_cm": 2.1, "area_cm2": 5.3, "depth_cm": 0.4,
      "is_calibrated": true,
      "tissue_json": { },
      "infection_present": true, "infection_prob": 0.87,
      "ischaemia_present": false, "ischaemia_prob": 0.12,
      "risk_badge": "infection",
      "models_version": "v1",
      "source": "offline"
    }
  ]
}

→ { "synced": ["local_uuid", …], "failed": [ { "local_uuid": "…", "reason": "…" } ] }
```

Rules the mobile client can rely on:

1. **`local_uuid` is generated at capture, not at upload.** This is what makes the
   endpoint idempotent — resending a batch whose response was lost updates rather
   than duplicates.
2. **Partial success is normal.** One bad record never discards the batch. Keep any
   `local_uuid` that is not in `synced` marked `pending_sync` and retry it.
3. **The owning patient is derived from the token**, never from the request body — a
   device cannot file scans against another patient's chart.
4. Retry with **exponential backoff** (1s → 5s → 30s → 2min).

## Verification

The API and dashboard were driven end-to-end against a live server, not just built:

- **Idempotency** — the same 2-record batch sent twice → `wound_scans = 2`, not 4.
- **Patient isolation** — a second patient reading `/wound-scans` gets `total: 0`.
- **RBAC** — a patient token on `/dashboard/stats` and `/patients` → `403`.
- **Device hijack** — claiming another account's `device_uuid` → `409`.
- **Privilege escalation** — `"role":"admin"` in the register body → stored role is `patient`.
- **UI (Playwright, Chromium)** — guard redirect, login, stat tiles rendering live API
  numbers, scan/device/patient tables, Arabic switch flipping `dir="rtl"` and surviving a
  reload, deep links, and the mobile off-canvas drawer. **0 console errors.**

## Open decisions before this ships

These are carried from `PHASE2_TRACKER.md` in the app repo and are not yet resolved:

1. **Firebase vs. Sanctum identity.** The app already authenticates with Firebase. Running
   both is a real hazard — decide on one, or exchange a Firebase token for a Sanctum token.
2. **⚠️ Patient consent.** The app's SUS participant declaration currently tells users their
   data is **on-device** and **not linked to medical data**. Syncing wound images and
   clinical records to this server contradicts that. **The consent text and privacy policy
   must be updated, and users given a real choice, before any sync ships to patients.**
3. **Model-version parity.** Mode A (server inference) and Mode B (on-device) must report
   `models_version` so two users don't get different results from the same photo.
4. **Image upload is not implemented.** `image_path` exists on the schema, but the sync
   endpoint currently accepts metadata only. Uploading images needs a decision on storage,
   encryption at rest, and retention.
