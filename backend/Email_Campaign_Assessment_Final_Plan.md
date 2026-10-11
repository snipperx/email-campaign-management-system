# Email Campaign Management System
## Final Technical Implementation Plan

**Assessment:** Intermediate Full Stack Developer  
**Stack:** Laravel, PHP, MySQL, Laravel Queue, Angular 17+, TypeScript, SCSS, Bootstrap 5  
**Time budget:** 8–12 hours across three calendar days  
**Scope:** Campaign management tasks 1–11. The email-template builder is out of scope, as confirmed by the recruiter.

---

## 1. Objective

Build a working full-stack application that allows a marketing user to:

1. Create an email campaign.
2. Validate campaign details and recipient email addresses.
3. Persist the campaign and one recipient-tracking record per email.
4. Queue one asynchronous processing job per recipient.
5. Simulate email sending and record each recipient's result.
6. View campaigns, campaign details, and recipient statuses through Angular.

The emphasis is on end-to-end functionality, sound technical judgement, readable code, and tests—not production-scale email delivery or a highly polished UI.

---

## 2. Final Decisions and Assumptions

| Area | Decision |
|---|---|
| Authentication | Out of scope; single-user assessment application. |
| Email provider | No external provider; sending is simulated. |
| Database | MySQL. |
| Queue driver | Laravel database queue. |
| Queue granularity | One queued job per recipient. |
| Database consistency | Create the campaign and recipient records in one database transaction. |
| Dispatch timing | Dispatch jobs after the transaction commits. |
| Recipient validation | Trim whitespace, apply the documented case-insensitive duplicate policy, and reject duplicates rather than silently removing them. |
| Recipient status | `pending`, `processing`, `sent`, `failed`. |
| Campaign status | `queued`, `processing`, `done`. |
| Campaign completion | `done` when no recipient remains pending or processing, even if one or more recipients failed. |
| Public identifiers | Use integer IDs as specified by the assessment; do not add ULIDs unless required later. |
| Pagination | Optional; begin with the simple response contract documented below. |
| API versioning | Use the requested `/api/campaigns` paths; no `/v1` prefix. |
| API validation errors | Return the assessment-compatible `{ "error": "...", "details": {...} }` structure. |
| Backend tests | PHPUnit by default. Pest is also acceptable if it is already the project standard; Pest runs on PHPUnit. |
| API documentation | Maintain an OpenAPI 3.0 specification at `backend/docs/openapi.yaml` (or `docs/openapi.yaml`); interactive Swagger UI is optional. |
| Business logic structure | Use focused Laravel Actions for campaign creation, recipient processing, and campaign status recalculation. |
| Frontend tests | Jasmine/Karma or the Angular test runner configured by the project. |
| Storybook | Skip; the template builder is out of scope and the time budget is limited. |
| Styling | SCSS and Bootstrap 5 grid/utilities only; no Bootstrap JavaScript. |
| State | Angular Signals and/or simple services; no global store. |
| SQL safety | Eloquent ORM and parameterised query-builder operations; no SQL string concatenation with untrusted input. |

---

## 3. Architecture

```text
Angular standalone components
          |
          | HTTP / JSON
          v
Laravel REST API
          |
          +--> Form Request validation
          |
          +--> Campaign Controller
                    |
                    v
            Campaign Actions
           /        |       \
          v         v        v
    CreateCampaign Recalculate ProcessEmailRecipient
        Action      Status       Action
                    Action
          |
          v
    Database transaction
       /          \
      v            v
  Campaigns     Email Jobs
                    |
                    | dispatch after commit
                    v
             Laravel database queue
                    |
                    v
             ProcessEmailJob worker
                    |
                    v
          Update recipient and campaign status
                    |
                    v
                  MySQL
```

### Responsibilities

- **Form Request:** Validate and normalise request input.
- **Controller:** Handle HTTP requests, coordinate concerns, and delegate to the relevant Action; keep controllers thin.
- **CreateCampaignAction:** Create the campaign and recipient records transactionally within a single database transaction.
- **ProcessEmailRecipientAction:** Encapsulate the core business logic for processing one recipient.
- **RecalculateCampaignStatusAction:** Derive and update campaign status from recipient statuses when outcomes change.
- **Eloquent models:** Represent campaigns, recipient records, and their relationship.
- **Queued job:** Orchestrate asynchronous execution, retry policy, and terminal failure handling; delegate domain logic to actions.
- **API Resources or explicit response mappers:** Expose only fields needed by the frontend.
- **Angular API service:** Encapsulate HTTP requests and typed responses.
- **Angular components:** Manage forms, loading/error states, lists, and details.

Use Actions where they encapsulate a meaningful business operation, not as wrappers around trivial code. Laravel's container can inject these concrete Action classes without custom bindings. Keep the design simple: do not introduce repositories, a global state library, an outbox, or other infrastructure unless implementation reveals a concrete need.

---

## 4. Database Design

### 4.1 `campaigns`

| Column | Type | Constraints / purpose |
|---|---|---|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `name` | VARCHAR(255) | Required |
| `subject` | VARCHAR(255) | Required |
| `body` | TEXT | Required; maximum 10,000 characters enforced by validation |
| `recipient_count` | UNSIGNED INT | Required |
| `status` | VARCHAR(20) | `queued`, `processing`, or `done` |
| `created_at` | TIMESTAMP | Laravel timestamp |
| `updated_at` | TIMESTAMP | Laravel timestamp |

Indexes:
- Primary key on `id`.
- Index on `status`.
- Index on `created_at`.

### 4.2 `email_jobs`

This is the application's recipient-tracking table. It is distinct from Laravel's own `jobs` queue table.

| Column | Type | Constraints / purpose |
|---|---|---|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `campaign_id` | BIGINT UNSIGNED | Foreign key to `campaigns.id` |
| `recipient_email` | VARCHAR(255) | Required |
| `status` | VARCHAR(20) | `pending`, `processing`, `sent`, or `failed` |
| `sent_at` | TIMESTAMP NULL | Set when simulated sending succeeds |
| `error_message` | TEXT NULL | Sanitised final failure information |
| `created_at` | TIMESTAMP | Laravel timestamp |
| `updated_at` | TIMESTAMP | Laravel timestamp |

Indexes:
- Primary key on `id`.
- Foreign key/index on `campaign_id`.
- Index on `status`.
- Composite index on `(campaign_id, status)`.

Use a foreign key with cascading delete from a campaign to its recipient records.

### 4.3 Laravel queue storage

Configure Laravel's database queue and run the queue-table migration required by the installed Laravel version. Laravel's queue table stores pending queue payloads; `email_jobs` stores recipient status and campaign reporting data. They are not interchangeable.

### 4.4 Models and relationships

- `Campaign` has many `EmailJob` records.
- `EmailJob` belongs to `Campaign`.
- Protect mass assignment with appropriate `$fillable` properties or equivalent model configuration.
- Use PHP enums for status values if supported cleanly by the chosen Laravel version.

---

## 5. API Contract

### 5.1 Create a campaign

`POST /api/campaigns`

Request:

```json
{
  "name": "Spring Sale",
  "subject": "50% Off This Weekend!",
  "body": "Check out our amazing deals...",
  "recipient_emails": [
    "john@example.com",
    "mary@example.com"
  ]
}
```

Success: **HTTP 201 Created**

```json
{
  "campaign_id": 1,
  "recipient_count": 2,
  "status": "queued"
}
```

Validation failure: **HTTP 422 Unprocessable Entity**

```json
{
  "error": "Invalid input",
  "details": {
    "subject": [
      "The subject field is required."
    ],
    "recipient_emails.1": [
      "Duplicate recipient email addresses are not allowed."
    ]
  }
}
```

Validation rules:

| Field | Rules |
|---|---|
| `name` | Required, string, maximum 255 characters |
| `subject` | Required, string, maximum 255 characters |
| `body` | Required, string, maximum 10,000 characters |
| `recipient_emails` | Required array, minimum one item, maximum 500 items |
| `recipient_emails.*` | Valid RFC email address; duplicates rejected (case-insensitive) |

Normalise surrounding whitespace before validation. If the application adopts case-insensitive duplicate detection, normalise the addresses consistently before applying the duplicate rule. Do not silently deduplicate and accept the request.

The backend is authoritative even though Angular also validates the form.

### 5.2 List campaigns

`GET /api/campaigns`

Success: **HTTP 200 OK**

```json
{
  "data": [
    {
      "id": 1,
      "name": "Spring Sale",
      "subject": "50% Off This Weekend!",
      "recipient_count": 2,
      "status": "processing",
      "created_at": "2026-10-10T08:30:00Z"
    }
  ]
}
```

Return newest campaigns first. Pagination is optional; if added, document the response shape and update frontend/tests consistently.

### 5.3 View campaign details

`GET /api/campaigns/{id}`

Success: **HTTP 200 OK**

```json
{
  "data": {
    "id": 1,
    "name": "Spring Sale",
    "subject": "50% Off This Weekend!",
    "body": "Check out our amazing deals...",
    "recipient_count": 2,
    "status": "processing",
    "created_at": "2026-10-10T08:30:00Z",
    "recipients": [
      {
        "id": 1,
        "email": "john@example.com",
        "status": "sent",
        "sent_at": "2026-10-10T08:31:00Z"
      },
      {
        "id": 2,
        "email": "mary@example.com",
        "status": "pending",
        "sent_at": null
      }
    ]
  }
}
```

Missing campaign: **HTTP 404 Not Found**

```json
{
  "error": "Campaign not found"
}
```

### 5.4 HTTP response conventions

| Scenario | HTTP status |
|---|---:|
| Campaign created | 201 |
| Campaign list/details retrieved | 200 |
| Validation error | 422 |
| Campaign not found | 404 |
| Too many requests (rate limit) | 429 |
| Unexpected server error | 500 |

Keep error responses consistent. Do not expose stack traces, SQL details, secrets, or internal diagnostics in production responses.

---

## 6. Campaign Creation and Queue Dispatch

Implement the following flow in `CreateCampaignAction`:

1. Validate the request via `StoreCampaignRequest`.
2. Begin a database transaction.
3. Create the campaign with `recipient_count` set to the number of valid recipients and status `queued`.
4. Create one `email_jobs` record per recipient, each initially `pending`.
5. Dispatch one `ProcessEmailJob` per recipient configured with `afterCommit()` behaviour.
6. Commit the database transaction.
7. Return the created `Campaign` instance.

If campaign or recipient creation fails before commit, roll back the transaction so partial application data is not persisted.

**Known limitation:** After-commit dispatch prevents workers from seeing uncommitted records, but it does not guarantee that every queue dispatch succeeds after the database commit. A transactional outbox would address that gap, but it is out of scope for this time-limited assessment. Document the limitation if appropriate.

---

## 7. Queue Worker and Status Handling

### 7.1 Job payload and retry policy

- Pass the `email_jobs.id` to the queued job, not a serialised Eloquent model.
- Configure explicit `$tries`, `backoff()`, and `$timeout`.
- Choose retry values that are compatible with the queue worker timeout.
- Let transient exceptions propagate so Laravel can retry them.
- Use an explicit permanent-failure path where retrying is not appropriate.
- Implement terminal failure handling in `failed()` or an equivalent reliable mechanism.
- Make terminal status updates safe to repeat.

Example policy:

```php
public int $tries = 3;

public int $timeout = 30;

public function backoff(): array
{
    return [10, 30];
}
```

### 7.2 Atomic claiming

Use a conditional database update to claim a pending record, rather than keeping a transaction lock open during processing:

```php
$claimed = EmailJob::query()
    ->whereKey($emailJobId)
    ->where('status', EmailJobStatus::Pending)
    ->update([
        'status' => EmailJobStatus::Processing,
        'updated_at' => now(),
    ]);

if ($claimed !== 1) {
    return;
}
```

The conditional update ensures that only one competing worker can successfully change the same record from `pending` to `processing`. It does not guarantee exactly-once delivery if a worker crashes after sending but before saving the final result.

### 7.3 Processing flow

1. Load the recipient record using the supplied ID.
2. Attempt the conditional atomic claim.
3. Exit if the record is missing, already claimed, or no longer pending.
4. Set the campaign status to `processing` if it was `queued`.
5. Delegate execution to `ProcessEmailRecipientAction`.
6. Simulate email sending and log useful, non-sensitive information.
7. On success, mark the recipient `sent` and set `sent_at`.
8. On a retryable failure, allow the exception to propagate so Laravel queue retries work.
9. When retries are exhausted or a terminal failure is caught in `failed()`, mark the recipient `failed` and record a sanitised error message.
10. Trigger `RecalculateCampaignStatusAction` after each terminal recipient outcome.

Do not hold database locks while performing a potentially slow external operation. Since sending is simulated, avoid unnecessary delays such as `sleep()` unless they are specifically useful for demonstrating asynchronous processing.

### 7.4 Campaign status calculation

- `queued`: no recipient processing has started.
- `processing`: processing has started and at least one recipient remains pending or processing.
- `done`: no recipient remains pending or processing.

A campaign can be `done` when some recipients are `failed`; recipient-level statuses preserve the outcome details.

### 7.5 Worker crash limitation

A worker crash can leave a record in `processing`. A retry that only claims `pending` records will not reclaim it. For this assessment, document this recovery limitation clearly in the README. Do not claim that atomic claiming alone solves abandoned-work recovery or guarantees exactly-once delivery.

---

## 8. Exception Handling

Use Laravel's exception configuration in `bootstrap/app.php` for consistent JSON rendering.

- **Validation failures:** HTTP 422 with `{ "error": "Invalid input", "details": {...} }`.
- **Missing campaigns:** HTTP 404 with `{ "error": "Campaign not found" }`.
- **Rate limiting exceeded:** HTTP 429 with `{ "error": "Too many requests" }`.
- **Unexpected errors:** HTTP 500 with generic `{ "error": "An unexpected error occurred." }` and diagnostic details logged internally.

Avoid exposing stack traces, SQL errors, or sensitive environment details in API responses.

---

## 9. Angular Application

Use Angular standalone components, strict TypeScript, Reactive Forms, SCSS, Bootstrap 5 grid/utilities, and an API service. Keep state local with Signals or simple services.

### 9.1 Suggested structure

```text
frontend/src/app/
├── core/
│   ├── models/
│   │   ├── campaign.model.ts
│   │   ├── campaign-detail.model.ts
│   │   ├── email-job.model.ts
│   │   └── api-error.model.ts
│   └── services/
│       └── campaign-api.service.ts
├── features/
│   └── campaigns/
│       ├── campaign-list/
│       ├── campaign-create/
│       ├── campaign-detail/
│       └── campaigns.routes.ts
├── shared/
│   ├── components/
│   │   ├── loading-spinner/
│   │   ├── empty-state/
│   │   ├── error-alert/
│   │   └── status-badge/
│   └── validators/
├── app.routes.ts
├── app.config.ts
└── app.component.ts
```

### 9.2 Routes

| Route | Purpose |
|---|---|
| `/campaigns` | Campaign list/dashboard |
| `/campaigns/new` | Create a campaign |
| `/campaigns/:id` | Campaign details and recipient statuses |
| Default route | Redirect to `/campaigns` |

### 9.3 Campaign creation form

Fields:
- Campaign name.
- Subject.
- Body.
- Recipients textarea, one email per line.

Client-side validation:
- Required name, subject, and body.
- Character limits matching backend validation.
- At least one valid recipient.
- Duplicate recipients rejected.
- Clear inline errors and API error summary.
- Loading state while submitting.
- Success message showing campaign ID and recipient count.

Parse recipient lines by trimming surrounding whitespace and applying the agreed case-insensitive duplicate policy. Do not silently discard duplicates.

### 9.4 Campaign list

Display:
- Campaign name.
- Subject.
- Recipient count.
- Status.
- Created date/time.

Include loading, error, and empty states. Retrieve real data from `GET /api/campaigns`; do not hard-code campaigns.

### 9.5 Campaign details

Display:
- Campaign name, subject, body, status, recipient count, and creation date.
- Recipient email addresses.
- Individual statuses and sent timestamps where available.

Refresh data when the user revisits the screen. Optional polling while the campaign is active is useful; stop polling when the campaign becomes `done`.

### 9.6 Basic user experience

- Responsive layout.
- Navigation between list, create, and detail views.
- Accessible labels and visible validation feedback.
- Meaningful API error handling.
- Status badges for queued, processing, done, pending, sent, and failed.
- No Bootstrap JavaScript.

---

## 10. Automated Testing

### 10.1 Backend tests

Use PHPUnit unless the project standard already uses Pest. Test at least:

| Test | Expected result |
|---|---|
| Valid campaign submission | HTTP 201 and campaign persisted |
| Campaign fields | Correct values stored |
| Missing required fields | HTTP 422 |
| Invalid email | HTTP 422 |
| Duplicate emails | HTTP 422 |
| Empty recipient list | HTTP 422 |
| Multiple recipients | One tracking record per recipient |
| Recipient relationships | Correct campaign foreign key |
| Queue dispatch | Expected jobs dispatched after commit |
| Successful processing | Recipient becomes `sent` |
| Retry exhaustion/failure | Recipient becomes `failed` |
| Campaign completion | Campaign becomes `done` when no active work remains |
| Campaign list | HTTP 200 with expected fields |
| Campaign detail | Recipient data and statuses returned |
| Missing campaign | HTTP 404 |
| Atomic claim | A second claim does not process the same pending record |

Use `RefreshDatabase` for database tests.

Use `Queue::fake()` or `Bus::fake()` to test dispatching. Test actual job processing separately; a faked queue does not execute the queued jobs. Invoke the processing logic directly where appropriate and assert the database state. Add a focused integration test if the time budget allows.

Test that queue dispatch occurs after the database transaction commits, using the appropriate Laravel queue/testing APIs for the installed version.

### 10.2 Frontend tests

Prioritise:
1. Empty form is invalid.
2. Invalid email addresses are rejected.
3. Duplicate recipients are rejected.
4. Valid submission calls the API service.
5. Success feedback displays campaign ID and recipient count.
6. API validation errors are displayed.
7. Campaign list displays retrieved data and an empty state.
8. Campaign details display recipient addresses and statuses.
9. API service uses the correct endpoints and request body.

Do not spend more time on styling tests than on the core submission and processing workflow.

---

## 11. Security and Quality Checklist

- [ ] Backend validates every submitted field.
- [ ] Eloquent/query builder uses parameterised operations.
- [ ] No SQL is built by concatenating untrusted input.
- [ ] Mass assignment is controlled via `$fillable`.
- [ ] API responses expose only required fields.
- [ ] Secrets are excluded from Git.
- [ ] `.env.example` contains placeholders, not real credentials.
- [ ] Validation responses use the agreed JSON format.
- [ ] HTTP response codes are appropriate.
- [ ] Unexpected errors do not expose stack traces or SQL details.
- [ ] Recipient failures do not crash the entire queue worker.
- [ ] Campaign and recipient status transitions are tested.
- [ ] PHP code follows PSR-12 conventions.
- [ ] TypeScript strict mode is enabled.
- [ ] No campaign data is hard-coded in Angular.

---

## 12. Suggested Repository Structure

```text
email-campaign-management/
├── app/                  # Laravel backend project
├── database/
├── routes/
├── tests/
├── docs/
│   └── openapi.yaml
├── .env.example
├── Dockerfile            # Multi-stage build for both projects
├── docker-compose.yml
├── frontend/
│   ├── src/
│   ├── angular.json
│   ├── package.json
│   └── README.md
└── README.md
```

Keep Laravel at the repository root and Angular under `frontend/` as independent projects. Use separate Compose services for the API, queue worker, database, and frontend while building both application images from the shared root Dockerfile. Provide local development commands for each project and a root README for running the full stack.

---

## 13. Time Plan (Target: 11 Hours)

| Phase | Estimated time |
|---|---:|
| Laravel setup, migrations, models, enums | 1 hour |
| Validation, transactional campaign creation with Actions | 1.5 hours |
| Queue jobs, retries, status updates, failure handling | 1.5 hours |
| List/detail APIs and error handling | 45 minutes |
| Backend automated tests | 1 hour |
| Angular setup, routing, API service | 45 minutes |
| Campaign creation form and validation | 1.25 hours |
| Campaign list and details | 1.25 hours |
| Angular tests | 30 minutes |
| README, OpenAPI specification, and setup instructions | 45 minutes |
| End-to-end testing and final fixes | 45 minutes |
| **Total** | **11 hours** |

If time becomes constrained, follow this order:
1. Campaign API, validation, database, queue, and worker.
2. Angular creation form and API integration.
3. Campaign list and details.
4. Core automated tests.
5. UI refinements and optional features.

---

## 14. Definition of Done

Before submission, verify this workflow from a clean setup:

1. Configure MySQL and Laravel environment.
2. Run migrations, including Laravel queue-table migrations.
3. Start the Laravel API.
4. Start Angular.
5. Start the queue worker.
6. Create a campaign with three recipients through the UI.
7. Confirm one campaign and three recipient-tracking records exist.
8. Confirm one queue job is dispatched per recipient.
9. Confirm the worker processes recipients asynchronously.
10. Confirm recipient statuses reach `sent` or `failed`.
11. Confirm campaign status becomes `done` when no recipients remain pending or processing.
12. Open campaign details and verify all recipient statuses.
13. Submit invalid and duplicate emails and verify clear errors.
14. Run backend and frontend tests.
15. Confirm `backend/docs/openapi.yaml` (or `docs/openapi.yaml`) documents all three endpoints and matches the implemented response shapes.
16. Confirm the README covers all setup/run/test commands.
17. Confirm no secrets or local `.env` files are committed.
18. Clone the repository into a clean directory, if time allows, and follow the README to verify reproducibility.

---

## 15. README Requirements

The root README must document:
- Project overview and architecture.
- PHP, Composer, Node.js, Angular CLI, and MySQL prerequisites.
- Backend installation and environment configuration.
- Database creation and migrations.
- Queue configuration and worker command.
- Backend API start command.
- Frontend installation and start command.
- Backend and frontend test commands.
- API endpoints and example request/response bodies.
- Link/path to `docs/openapi.yaml` and instructions for viewing it in an OpenAPI-compatible viewer (e.g., Swagger Editor).
- Assumptions and technical decisions.
- Known limitations, including queue dispatch/recovery limitations if not addressed.

Do not commit `.env`, credentials, API keys, or real secrets.

---

## 16. API Documentation Requirements

Use OpenAPI 3.0 as the formal API contract. Keep the specification at `docs/openapi.yaml` (or `backend/docs/openapi.yaml`). For this time-boxed assessment, a committed YAML specification is sufficient; Swagger UI or another interactive viewer is optional and should not delay core functionality.

The specification must document:
- `POST /api/campaigns`: required fields, data types, constraints, duplicate-recipient policy, success response (201), and validation/rate-limit errors (422, 429 when rate limiting is enabled).
- `GET /api/campaigns`: list response schema, newest-first ordering, and pagination only if implemented.
- `GET /api/campaigns/{id}`: path parameter, detail response including recipient statuses, and not-found response (404).
- Campaign statuses (`queued`, `processing`, `done`) and recipient statuses (`pending`, `processing`, `sent`, `failed`).
- Shared error schemas, timestamp formats, and realistic request/response examples.

Keep the specification aligned with the actual Laravel implementation and tests. OpenAPI `uniqueItems: true` only rejects identical array elements; it does not fully describe case-insensitive email duplication, so explain that policy in the operation description and enforce it on the backend. Do not document endpoints, fields, authentication, pagination, or behaviours that the application does not implement.

---

## 17. Final Scope Boundary

### In scope
- Campaign creation and validation.
- Campaign and recipient persistence.
- Asynchronous per-recipient queue processing.
- Simulated sending.
- Recipient and campaign status updates.
- Campaign list and detail APIs.
- Angular create, list, and detail screens.
- Automated tests, README setup documentation, and an OpenAPI specification.

### Out of scope
- Real email-provider integration.
- Authentication and roles.
- Email-template drag-and-drop builder.
- Storybook.
- Production-scale delivery guarantees.
- Transactional outbox and advanced recovery infrastructure.
- Unnecessary API versioning, repositories, or global state management.

---

## Final Implementation Principle

Deliver the complete end-to-end workflow first. Keep each architectural choice proportionate to the assessment, test the important behaviour, and document limitations honestly. The strongest submission is a clean, reproducible application that works from Angular through Laravel and MySQL to the asynchronous worker—and back to the displayed campaign statuses.
