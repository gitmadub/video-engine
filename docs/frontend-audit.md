# Frontend Audit

## Final Structure

- `public/`: production-ready static web root with normalized routes and shared assets.
- `resources/mirrors/`: archived original mirrors from the DoodStream crawl.
- `tools/`: mirroring and rebuild utilities.
- `docs/`: generated architecture and backend endpoint audits.

## Routes Published In `public/`

- `/` -> `public/index.html`
- `/api-docs` -> `public/api-docs/index.html`
- `/contact` -> `public/contact/index.html`
- `/copyright` -> `public/copyright/index.html`
- `/earn-money` -> `public/earn-money/index.html`
- `/premium` -> `public/premium/index.html`
- `/terms-and-conditions` -> `public/terms-and-conditions/index.html`
- `/dashboard` -> `public/dashboard/index.html`
- `/videos` -> `public/videos/index.html`
- `/settings` -> `public/settings/index.html`
- `/request-payout` -> `public/request-payout/index.html`
- `/reports` -> `public/reports/index.html`
- `/remote-upload` -> `public/remote-upload/index.html`
- `/referrals` -> `public/referrals/index.html`
- `/premium-plans` -> `public/premium-plans/index.html`
- `/dmca-manager` -> `public/dmca-manager/index.html`

## Key Fixes

- Merged site and dashboard asset trees into one shared `/assets/` namespace.
- Restored missing Font Awesome Pro webfonts and Averta font files used by the dashboard panel stylesheet.
- Added a local `blob-shape-grey.svg` placeholder because the mirrored dashboard CSS referenced it but the asset was missing.
- Rewrote dashboard page links away from `*.html` file references to stable route-style paths.
- Switched the dashboard polling call to a local static JSON mock so the overview page does not spam a missing backend endpoint during static verification.

## Remaining Backend Work

- Notification, login, registration, password reset, account settings, payout, and DMCA actions still expect a real backend.
- Vue-based dashboard screens still rely on DoodStream JS bundles; they load statically now, but backend data flows are intentionally not faked beyond the dashboard summary mock.
