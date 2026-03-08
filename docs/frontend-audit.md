# Frontend Audit

Snapshot date: 2026-03-08

## Current app shape

- The runtime entrypoint is `index.php`, which boots `app/frontend.php`.
- `router.php` only exists to support PHP's built-in development server.
- The frontend is served directly from the repo root:
  - `index.html`
  - `pages/*.html`
  - `dashboard/*.html`
  - `assets/**`
  - `js/dood_load.js`
  - `api/dashboard-update.json`
- There is no `public/`, `resources/mirrors/`, or frontend build pipeline in this repository. The previous documentation described a structure that does not exist here.

## Routed pages

### Site pages

- `/` -> `index.html`
- `/api-docs` -> `pages/api-docs.html`
- `/contact` -> `pages/contact.html`
- `/copyright` -> `pages/copyright.html`
- `/earn-money` -> `pages/earn-money.html`
- `/premium` -> `pages/premium.html`
- `/terms-and-conditions` -> `pages/terms-and-conditions.html`

### Dashboard pages

- `/dashboard` -> `dashboard/index.html`
- `/dashboard/videos` -> `dashboard/videos.html`
- `/dashboard/settings` -> `dashboard/settings.html`
- `/dashboard/reports` -> `dashboard/reports.html`
- `/dashboard/remote-upload` -> `dashboard/remote-upload.html`
- `/dashboard/referrals` -> `dashboard/referrals.html`
- `/dashboard/premium-plans` -> `dashboard/premium-plans.html`
- `/dashboard/request-payout` -> `dashboard/request-payout.html`
- `/dashboard/dmca-manager` -> `dashboard/dmca-manager.html`

### Legacy and utility routes

- Root aliases `/videos`, `/settings`, `/reports`, `/remote-upload`, `/referrals`, `/premium-plans`, `/request-payout`, and `/dmca-manager` are redirected to `/dashboard/*`.
- `/data/dashboard-update.json` and `/dl?op=dashboard&update=1` both return the local JSON file in `api/dashboard-update.json`.
- `/genrate-api` and `/genrate-api/` redirect to `/dashboard/settings`.
- `/subscene/*` is routed, but only to a placeholder JSON response.
- `/?op=...` is used by most form and AJAX integrations.

## Frontend composition

- `index.html` is mostly static HTML plus auth modals and one Vue custom element: `<home-upload>`.
- `dashboard/videos.html`, `dashboard/remote-upload.html`, and `dashboard/premium-plans.html` are shell pages that rely on mirrored Vue bundles:
  - `<video-manager>`
  - `<remote-upload>`
  - `<my-premium>`
  - `<main-menu>`
  - `<main-sidebar>`
- `dashboard/index.html`, `dashboard/settings.html`, `dashboard/reports.html`, `dashboard/request-payout.html`, `dashboard/referrals.html`, and `dashboard/dmca-manager.html` are mostly static HTML with jQuery-driven interactions.
- `dashboard/settings.html` now renders every sidebar panel locally in the page:
  - account details
  - change password
  - change email
  - player settings
  - own adverts
  - FTP servers
  - custom domain
  - delete account
- The custom-domain panel is currently a frontend-only browser-local preview.
- The delete-account panel is currently a frontend-only confirmation form.
- `js/dood_load.js` is only an instant-prefetch helper. It is not an application script.
- The repo ships vendor-built bundles only. It does not include the original Vue source, build config, or component source files.

## Frontend findings

- The frontend still depends on mirrored third-party bundles for core dashboard behavior. Any backend work has to preserve those contracts or replace the bundles.
- Several routes are referenced by the frontend but are not implemented locally:
  - `/search`
  - `/upload`
  - `/tos`
  - referral `join/*`
- The dashboard still mixes root-level links (`/videos`, `/settings`, etc.) with `/dashboard/*` routes and depends on redirect behavior in PHP.
- Hard-coded account and third-party data is present in shipped HTML:
  - username `videoengine`
  - email `lzcoeyhl@telegmail.com`
  - user id `559348`
  - API key value in settings
  - FTP password in settings
  - referral code in referrals
  - Crisp website id and session metadata
- The settings page no longer depends on AJAX to switch panels, but most settings actions still do not persist to a real backend.
- This is a mirrored/static frontend, not a clean product frontend. Security cleanup and content normalization are still required before production use.

## High-impact gaps exposed by the UI

- Upload UX is not actually backend-ready. The home page and video manager expect real upload negotiation, upload result lookup, and upload target URLs.
- Remote upload, premium purchase, subtitle import, folder management, and most video actions are controlled by bundle AJAX calls that still need real backend handlers.
- Account settings persistence is still incomplete. Custom domain management and delete-account flows are only frontend previews, and the existing forms still post to stubs or redirects.
- The API docs page is only a static document. It does not reflect or exercise any local API implementation.
