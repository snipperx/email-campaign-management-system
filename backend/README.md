# Email Campaign Management System

An asynchronous, queue-driven Email Campaign Management System built with a **Laravel 12** API, a separate **Angular** frontend, **PHP 8.2+**, and **MySQL**.

This system allows creating email marketing campaigns, registering recipients, and dispatching individual sending jobs asynchronously via a database queue worker while tracking real-time status transitions.

The Laravel backend lives in `backend/` and the independent Angular application lives in `frontend/`. A single multi-stage [`Dockerfile`](Dockerfile) builds both projects into separate Docker Compose services.

---

## Architecture Overview

```text
Angular campaign dashboard
        │
        ▼
Nginx frontend (port 8080; proxies /api)
        │
        ▼
Laravel REST API (port 8000)
  ├── StoreCampaignRequest (RFC email & duplicate validation, max 500 recipients)
  ├── CampaignController
  │     ▼
  ├── Campaign Actions
  │     ├── CreateCampaignAction (Transactional campaign & recipient persistence)
  │     ├── ProcessEmailRecipientAction (Simulated dispatch & recipient updates)
  │     └── RecalculateCampaignStatusAction (Dynamic campaign status evaluation)
  │
  ├── Database Transaction (Atomic commit)
  │     ├── campaigns table
  │     └── email_jobs table (recipient tracking)
  │
  └── Database Queue (ProcessEmailJob dispatched with afterCommit)
        │
        ▼
   Queue Worker (php artisan queue:work)
        ├── Atomic record claim
        ├── Simulated delivery
        └── Status transitions (queued -> processing -> done)
```

### Architectural Highlights
- **Laravel Actions Pattern:** Business logic is encapsulated into single-responsibility actions (`CreateCampaignAction`, `ProcessEmailRecipientAction`, `RecalculateCampaignStatusAction`) keeping controllers thin.
- **Database Consistency:** Campaigns and their recipient records are persisted inside a database transaction. Queue jobs are dispatched using `.afterCommit()` so workers never pick up uncommitted records.
- **Atomic Concurrency Protection:** Queue workers claim records using conditional atomic queries (`status = 'pending'`), preventing duplicate processing across workers.
- **API Documentation:** Formal OpenAPI 3.0 specification maintained at [`docs/openapi.yaml`](docs/openapi.yaml).
- **Security & Rate Limiting:** IP rate limiting (`throttle:campaigns`), bounded recipient payloads (`min:1, max:500`), Eloquent parameterised queries, and mass-assignment protection.

---

## Prerequisites

- **PHP** >= 8.2 with `pdo_mysql`, `mbstring`, `bcmath`, `xml`, `curl` extensions and **Composer** >= 2.0 for local backend development
- **Node.js** >= 20.19 and **npm** for local frontend development
- **MySQL** >= 8.0 for local development
- **Docker & Docker Compose** to run the complete stack in containers

---

## Getting Started

### 1. Local Environment Setup

Clone the repository and install dependencies:

```bash
composer install
```

Configure your environment file:

```bash
cp .env.example .env
php artisan key:generate
```

Update your `.env` database and queue settings:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=email_campaigns
DB_USERNAME=campaign_user
DB_PASSWORD=secret

QUEUE_CONNECTION=database
```

Run database migrations:

```bash
php artisan migrate
```

---

### 2. Running the Frontend Locally

Install dependencies and start the Angular application:

```bash
cd ../frontend
npm ci
npm start
```

The frontend development server runs at `http://localhost:4200` and proxies `/api` requests to Laravel at `http://localhost:8000`.

---

### 3. Running the Full Stack with Docker Compose

From the backend project directory, build and start the backend, queue worker, MySQL database, and frontend:

```bash
docker compose up --build -d
```

If you are working from the repository root instead, use:

```bash
docker compose -f backend/docker-compose.yml up --build -d
```

The Laravel entrypoint runs database migrations when the API container starts and exits with an error if they fail. Open the frontend at `http://localhost:8080`; the API is also available at `http://localhost:8000`. Frontend API calls use the same-origin `/api` path and are forwarded to Laravel by Nginx.

Stop the services with:

```bash
docker compose down
```

### 4. Starting the Backend & Queue Worker

Run the local web server:

```bash
php artisan serve
```

In a separate terminal, start the background queue worker:

```bash
php artisan queue:work --tries=3 --timeout=30
```

---

## API Specification

Formal OpenAPI 3.0 specification is available at:
👉 [`docs/openapi.yaml`](docs/openapi.yaml)

To view it interactively:
- Open [`https://editor.swagger.io`](https://editor.swagger.io) and import `docs/openapi.yaml`.
- Or use the **OpenAPI (Swagger) Editor** extension in VS Code / Antigravity.

### Endpoints Overview

| Method | Endpoint | Description | Status Code |
|---|---|---|---|
| `POST` | `/api/campaigns` | Create campaign and queue recipient sending | `201 Created` |
| `GET` | `/api/campaigns` | List all campaigns (newest first) | `200 OK` |
| `GET` | `/api/campaigns/{id}` | Retrieve campaign details & recipient statuses | `200 OK` / `404` |

#### Create Campaign Example
`POST /api/campaigns`

```json
{
  "name": "Spring Sale 2026",
  "subject": "50% Off This Weekend!",
  "body": "Check out our latest seasonal offers.",
  "recipient_emails": [
    "alice@example.com",
    "bob@example.com"
  ]
}
```

Response (`201 Created`):
```json
{
  "campaign_id": 1,
  "recipient_count": 2,
  "status": "queued"
}
```

#### List Campaigns Example
`GET /api/campaigns`

Response (`200 OK`):
```json
{
  "data": [
    {
      "id": 1,
      "name": "Spring Sale 2026",
      "subject": "50% Off This Weekend!",
      "recipient_count": 2,
      "status": "processing",
      "created_at": "2026-10-11T08:30:00Z"
    }
  ]
}
```

#### Get Campaign Details Example
`GET /api/campaigns/1`

Response (`200 OK`):
```json
{
  "data": {
    "id": 1,
    "name": "Spring Sale 2026",
    "subject": "50% Off This Weekend!",
    "body": "Check out our latest seasonal offers.",
    "recipient_count": 2,
    "status": "processing",
    "created_at": "2026-10-11T08:30:00Z",
    "recipients": [
      {
        "id": 1,
        "email": "alice@example.com",
        "status": "sent",
        "sent_at": "2026-10-11T08:31:00Z"
      },
      {
        "id": 2,
        "email": "bob@example.com",
        "status": "pending",
        "sent_at": null
      }
    ]
  }
}
```

---

## Running Tests

### 1. PHPUnit / Pest Test Suite

Run the full automated backend test suite (Unit & Feature tests):

```bash
php artisan test
```

#### Test Coverage Highlights
- **`CampaignActionsTest`**: Verifies transactional execution of `CreateCampaignAction`, `ProcessEmailRecipientAction`, and `RecalculateCampaignStatusAction`.
- **`CampaignApiTest`**: Validates API endpoints, RFC email format verification, whitespace normalization, case-insensitive duplicate email rejection, list ordering, and 404 handling.
- **`ProcessEmailJobTest`**: Asserts atomic concurrency locking, retry backoff, simulated delivery, terminal failure handling, and state recalculations.
- **`CampaignServiceTest`**: Asserts complete transactional rollback on database write failures.

---

### 2. Postman Test Suite (Newman CLI & Postman Desktop)

An automated Postman collection complete with test assertions (`pm.test`) is available in [`postman/`](file:///home/nkosana/Documents/workstation/email-campaign-management-system/postman):

- **Collection:** [`postman/Email_Campaign_System.postman_collection.json`](file:///home/nkosana/Documents/workstation/email-campaign-management-system/postman/Email_Campaign_System.postman_collection.json)
- **Environment:** [`postman/Local.postman_environment.json`](file:///home/nkosana/Documents/workstation/email-campaign-management-system/postman/Local.postman_environment.json)

#### Running Postman Tests via CLI (Newman)
Ensure your local server is running (`php artisan serve`), then execute:

```bash
npx newman run postman/Email_Campaign_System.postman_collection.json --environment postman/Local.postman_environment.json
```

#### Running in Postman Desktop App
1. Open the Postman Desktop application.
2. Click **Import** (top left) and select both files from the `postman/` folder.
3. Select the **Local Development (127.0.0.1:8000)** environment in the top right environment dropdown.
4. Open the collection, click **Run Collection**, and click **Run Email Campaign Management System API**.

---

## Technical Decisions & Assumptions

1. **Simulated Email Provider:** Email delivery is simulated in `EmailSenderService` rather than calling an external third-party API, matching the assessment requirements.
2. **Case-Insensitive Deduplication:** Duplicate email addresses in requests are rejected with HTTP 422 rather than silently stripped, alerting API consumers of data anomalies.
3. **Queue Granularity:** Exactly one job is dispatched per recipient, enabling fine-grained retries and individual failure isolation.
4. **Known Concurrency Limitation:** Atomic claiming prevents competing workers from picking up the same pending record. However, if a worker crashes midway after sending an email but before writing the status, it requires recovery; an enterprise transactional outbox is omitted in favour of assessment scope.
