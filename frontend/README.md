# Campaign Manager (Angular)

Angular 21 front end for the Email Campaign Management assessment. Standalone components, strict
TypeScript and templates, signals for state, Bootstrap 5 CSS only (no Bootstrap JS).

## Setup

```bash
npm ci
npm start          # http://localhost:4200
```

The frontend calls the same-origin `/api` path. The Angular development server proxies it to
Laravel at `http://localhost:8000`; Docker's Nginx frontend applies the equivalent proxy.

## Tests and build

```bash
npx playwright install chromium
npm test -- --watch=false --browsers=chromium
npm run build
```

## Screens

- `/campaigns`: list with status badges, loading, empty and error states. Refreshes every 5 seconds
  while any campaign is not `done`.
- `/campaigns/new`: reactive form. Recipients go in a textarea, one email per line. Invalid and
  duplicate addresses are flagged before submit; API validation errors are shown on their fields.
- `/campaigns/:id`: campaign details, sent/pending/failed counts and every recipient with a status.
  Also refreshes while the campaign is not `done`.

## Assumptions

- No authentication.
- Duplicate recipient addresses are rejected in the form, not silently removed.
- Responses may be bare or wrapped as `{ data }`; the API service accepts both.
- `created_at` has no timezone in the contract, so it is shown in the browser's local time.
- Pagination is not implemented (optional in the brief).
