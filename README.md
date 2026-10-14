```markdown
# Email Campaign Management System

A full-stack application for creating, scheduling, and sending email
campaigns, with per-recipient delivery tracking and reliable,
idempotent processing.

## Stack

- **Backend:** Laravel (PHP 8.2+) — queue-driven sending, campaign status tracking
- **Frontend:** JavaScript SPA (see `frontend/`)
- **Database:** MySQL / PostgreSQL
- **Queue:** Redis (recommended) or database driver
- **Mail:** SMTP / Mailgun / SES

## Repository layout

```
.
├── backend/     Laravel application (API, jobs, mail delivery)
├── frontend/    UI for composing campaigns and viewing results
├── .dockerignore
├── .gitignore
└── README.md
```

## Features

- Compose campaigns with subject, body, and recipient list
- Queue one email job per recipient with retry + backoff
- **Idempotent processing** — no duplicate sends if a job is retried or dispatched twice
- Per-recipient status: `Pending` → `Processing` → `Sent` / `Failed`
- Campaign-level status derived from recipient outcomes
  (`Queued` → `Processing` → `Completed` / `Failed`)
- Error capture per failed recipient, with a sanitized message

## Getting started

### Prerequisites

- PHP >= 8.2 and Composer
- Node >= 18 and npm
- MySQL or PostgreSQL
- Redis (recommended for queues; the database driver also works)

### Backend

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed      # optional demo data
php artisan serve        # http://localhost:8000
```

The queue worker is **required** for sending emails:

```bash
php artisan queue:work
```

### Frontend

```bash
cd frontend
cp .env.example .env
npm install
npm run dev              # http://localhost:5173 (or your dev port)
```

Point the frontend at the backend API (e.g. `VITE_API_URL=http://localhost:8000`).

### Docker (optional)

```bash
docker compose up --build
```

## Environment

Key variables (see `backend/.env.example` and `frontend/.env.example`):

| Variable | Purpose |
|---|---|
| `DB_*` | Database connection |
| `QUEUE_CONNECTION` | `redis`, `database`, or `sync` (dev only) |
| `MAIL_MAILER`, `MAIL_*` | Outbound mail transport |
| `VITE_API_URL` | Backend base URL used by the frontend |

> Do **not** use `QUEUE_CONNECTION=sync` in production — sends will block
> the HTTP request and the retry mechanism will not function.

## Architecture notes

- Each recipient is dispatched as a `ProcessEmailJob`. Before sending, the
  job **atomically claims** its `EmailJob` row by transitioning it from
  `Pending` to `Processing` in a single conditional `UPDATE`. A duplicate
  dispatch cannot send the same email twice.
- On retry, the job also re-claims rows stuck in `Processing`, recovering
  from workers that died mid-flight.
- The parent campaign is promoted from `Queued` to `Processing` on the
  first successful claim.
- After every terminal recipient outcome, `RecalculateCampaignStatusAction`
  derives the campaign's status from the aggregate of its email jobs.

## Testing

```bash
cd backend
php artisan test

cd ../frontend
npm run test
```

## License

MIT
```

Adjust the frontend framework line, mail/queue drivers, database, and license to match your actual choices.
