# Backend Endpoint Audit

Snapshot date: 2026-03-08

This audit is based on the actual PHP dispatcher in `app/frontend.php`, the static HTML pages, and the mirrored JS bundles under `assets/**`.

## What exists today

### Routed by `app/frontend.php`

| Surface | Current behavior |
| --- | --- |
| `/?op=notifications` | Returns `[]` |
| `/?op=login_ajax` | Returns JSON redirect to `/dashboard` |
| `/?op=registration_ajax` | Returns a generic success message |
| `/?op=forgot_pass_ajax` | Returns a generic success message |
| `/?op=logout` | Redirects to `/` |
| `/?op=my_password` | Returns stub HTML panel |
| `/?op=my_email` | Returns stub HTML panel |
| `/?op=upload_logo` | Returns stub HTML panel |
| `/?op=premium_settings` | Returns stub HTML panel |
| `/?op=dmca_manager&loadmore=1` | Returns `NOK` |
| `/?op=videos_json` | Returns an empty datatable-style payload |
| `/?op=remote_upload_json` | Returns an empty queue payload |
| `/?op=upload_get_srv` | Returns a local placeholder JSON object |
| `/?op=pass_file` | Returns a generic `{status:"ok"}` stub |
| `/?op=change_thumbnail` | Returns a generic `{status:"ok"}` stub |
| `/?op=folder_sharing` | Returns a generic `{status:"ok"}` stub |
| `/?op=marker` | Returns a generic `{status:"ok"}` stub |
| `/?op=payments` | Returns a generic stub JSON, or a static checkout page when `amount` is present |
| `/?op=crypto_payments` | Returns a generic stub JSON |
| `op=register_save` | Redirects back |
| `op=forgot_pass` | Redirects back |
| `op=my_account` | Redirects back |
| `op=my_reports` | Redirects back |
| `op=request_money` | Redirects back |
| `/data/dashboard-update.json` | Returns `api/dashboard-update.json` |
| `/dl?op=dashboard&update=1` | Returns `api/dashboard-update.json` |
| `/genrate-api` | Redirects to `/dashboard/settings` |
| `/subscene/*` | Returns a generic empty JSON payload |

The settings page also exposes `Custom Domain` and `Delete Account` panels, but those are frontend-only right now. There are no local PHP handlers for domain CRUD, DNS validation, account deletion requests, or a final delete-confirmation workflow.

## Contract mismatches already visible

These are not just "missing features". The current stubs do not match what the shipped JS bundles expect.

### Upload flow

- `/?op=upload_get_srv`
  - Bundle expectation: `{ success: true, server: { srv_url, disk_id } }`
  - Current stub: `{ status: "ok", server: "local", upload_url: "/dashboard/remote-upload" }`
- `/?op=pass_file`
  - Bundle expectation: either a `status: "fail"` gate or a completed upload result object with `links[]`
  - Current stub: generic success message only
- `op=upload_results_json`
  - Called by the upload bundles after a successful upload
  - Not implemented at all
- `/upload/{disk_id}`
  - The upload bundles try to POST file data to a real upload target
  - Not implemented locally

### Video manager

- `/?op=videos_json`
  - Used for the empty datatable payload
  - Also used by the bundle to save uploader content type and expects `{ status, message }`
  - Current implementation only supports the datatable-shaped response
- `/?op=change_thumbnail`
  - Bundle expects modal/body content or a workflow response
  - Current stub returns generic JSON only
- `/?op=folder_sharing`
  - Bundle expects HTML to inject into a modal
  - Current stub returns generic JSON only
- `/?op=marker`
  - Bundle expects real save/load behavior for video markers
  - Current stub returns generic JSON only
- `/subscene/search`, `/subscene/fetch`, `/subscene/dl`
  - Bundle expects searchable subtitle results and import actions
  - Current route returns one placeholder shape for every path

### Remote upload

- `/?op=remote_upload_json`
  - The initial list shape is close enough for an empty screen
  - The bundle also uses this endpoint for:
    - queue creation
    - retry
    - delete
    - clear all
    - clear errors
    - restart errors
  - Those action responses must include real `status` and `message` fields and mutate queue state

### Payments and premium

- `/?op=crypto_payments`
  - Bundle expects `status: "OK"` plus `qr`, `payment_uri`, `amount`, `currency_code`, `currency_name`, `address`
  - Current stub returns only a generic message
- `/?op=payments`
  - Balance flow expects HTML content for a modal
  - PayPal flow opens a checkout window with `amount`, `type`, `r`, and optional `premium_bw`
  - Current implementation does not provide a usable payment contract

## Backend features still to build

### 1. Authentication and account state

- Real login, registration, password reset, logout, and session handling
- Optional OTP flow, because the login modal already includes `loginotp`
- Real notifications payloads
- Account profile and settings persistence
- Account deletion request, confirmation, and irreversible delete workflow
- Custom domain CRUD, ownership validation, and DNS status checks

### 2. Upload platform

- Upload server negotiation
- Real upload target endpoints
- Upload result lookup (`upload_results_json`)
- File validation, quota rules, premium size limits, content-type handling
- Post-upload processing and result links

### 3. Video library and folders

- Folder create, rename, delete, list, and tree building
- Video list, search, filtering, pagination, and export links
- Rename, delete, move, marker editing, sharing, and thumbnail updates
- Subtitle upload and subtitle remote import

### 4. Remote upload queue

- Queue creation from URLs
- Job storage, retries, error states, delete, clear, and progress updates
- Supported-host validation and download worker pipeline

### 5. Dashboard and reporting

- Real dashboard counters
- Earnings, traffic, and usage history
- Reports filtered by date range
- Storage usage and top files

### 6. Billing and payouts

- Premium account purchases
- Premium bandwidth purchases
- Crypto checkout flow
- PayPal or other checkout flow
- Balance deductions
- Payout request submission and processing
- Referral earnings and referral history

### 7. Moderation and abuse flows

- DMCA list storage and pagination
- DMCA export/load-more behavior
- Copyright/report intake and moderation actions

### 8. Public API platform

- API key generation and rotation
- A real REST API surface for account, upload, folder, file, remote upload, DMCA, and search features
- Rate limiting, API auth, and documentation sync with the frontend API docs page

## Missing local surfaces referenced by the frontend

- `/search`
- `/upload`
- `/tos`
- referral `join/*`

These either need real implementations or all references must be removed from the mirrored frontend.

## Recommended implementation order

1. Authentication and persistent account model
2. Upload negotiation, upload target, and upload result APIs
3. Video list and folder CRUD
4. Remote upload queue
5. Dashboard stats and reports
6. Billing, payouts, and referrals
7. Public API and API key management
8. DMCA and moderation tooling
