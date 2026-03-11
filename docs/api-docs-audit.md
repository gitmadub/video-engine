# API Docs Page Audit

Snapshot date: 2026-03-08

File audited: `pages/api-docs.html`

## What the page is

- `pages/api-docs.html` is a static documentation page.
- It documents an external FileHost API hosted at `https://filehost.net/api`, not a local API implemented by this repository.
- The page itself does not call local backend endpoints. It is only rendered as static content at `/api-docs`.

## API surface claimed by the page

### Account

- `GET /api/account/info`
- `GET /api/account/stats`
- `GET /api/dmca/list`

### Upload

- `GET /api/upload/server`
- `GET /api/file/clone`

### Remote upload

- `GET /api/upload/url`
- `GET /api/urlupload/list`
- `GET /api/urlupload/status`
- `GET /api/urlupload/slots`
- `GET /api/urlupload/actions`

### Folders

- `GET /api/folder/create`
- `GET /api/folder/rename`
- `GET /api/folder/list`

### Files

- `GET /api/file/list`
- `GET /api/file/check`
- `GET /api/file/info`
- `GET /api/file/image`
- `GET /api/file/rename`
- `GET /api/file/move`
- `GET /api/search/videos`

### Extras

- Remote poster query parameter on embed URLs
- Remote subtitle query parameter on embed URLs
- Remote subtitle JSON query parameter on embed URLs

## Coverage against this repo

| Surface documented on `/api-docs` | Implemented locally |
| --- | --- |
| `/api/account/info` | No |
| `/api/account/stats` | No |
| `/api/dmca/list` | No |
| `/api/upload/server` | No direct equivalent |
| `/api/file/clone` | No |
| `/api/upload/url` | No |
| `/api/urlupload/list` | No public API equivalent |
| `/api/urlupload/status` | No public API equivalent |
| `/api/urlupload/slots` | No public API equivalent |
| `/api/urlupload/actions` | No public API equivalent |
| `/api/folder/create` | No |
| `/api/folder/rename` | No |
| `/api/folder/list` | No |
| `/api/file/list` | No public API equivalent |
| `/api/file/check` | No |
| `/api/file/info` | No |
| `/api/file/image` | No |
| `/api/file/rename` | No public API equivalent |
| `/api/file/move` | No public API equivalent |
| `/api/search/videos` | No |

The current PHP app only exposes `/?op=...` style frontend stubs plus a few utility routes. It does not expose the REST API advertised by the page.

## Page-level issues

- The page is branded as FileHost.net/DoodAPI rather than this project.
- It links users to external settings and external domains for API keys.
- It contains duplicate anchor ids:
  - `id="account"` is reused
  - `id="php"` is reused for both PHP and Python client sections
- The page claims a rate limit and response contracts that are not enforced or implemented locally.
- The examples still use external upload and embed hosts.

## Backend work required if this page is meant to stay

- Build a real REST API under a stable namespace such as `/api/...`
- Add API key issuance, rotation, and validation
- Implement account, upload, remote upload, folder, file, search, and DMCA resources
- Add rate limiting and consistent JSON envelopes
- Decide whether the public API should mirror the documented Dood-style contract or be rewritten to a local contract
- Update the page so the documented URLs, hostnames, examples, and auth flow match the real implementation

## Recommendation

- If the project plans to ship a public API, keep the page but rewrite it after the backend exists.
- If the page is only a mirrored placeholder, mark it as reference-only or remove it to avoid claiming non-existent API capabilities.
