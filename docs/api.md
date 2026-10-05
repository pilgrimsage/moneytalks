# API Specification (v1, API-first)

WhatsApp is the primary client, but all operations go through the same services, and
`/api/v1` exposes them for the admin panel, tests and future clients. Business logic
lives in services, never in controllers.

## 1. Conventions
- Base: `/api/v1`, JSON, `Authorization: Bearer <token>` (Sanctum), `Accept: application/json`.
- Money in responses: `{ "amount_minor": 25000, "currency": "INR", "formatted": "₹250.00" }`.
- Dates ISO-8601; business dates also returned as `occurred_on` (user-local `YYYY-MM-DD`).
- Pagination: cursor-based (`?cursor=…&limit=…`). Errors: RFC 7807-style
  `{type, title, status, detail, errors{}}`.
- Writes accept `Idempotency-Key` header (stored against the ledger idempotency key).
- Rate limits per token and per IP; versioned (`v1` never breaks; additive changes only).

## 2. Public (not under /api/v1, signature-authenticated)
| Method | Path | Purpose |
|---|---|---|
| GET | `/webhooks/whatsapp` | Meta verification handshake |
| POST | `/webhooks/whatsapp` | Inbound events (signature verified, fast 200) |
| GET | `/up` | liveness |

## 3. Resource endpoints (user-scoped, used by admin "view as", tests, future apps)

| Resource | Endpoints |
|---|---|
| users | `GET /users/me`, `PATCH /users/me` (name, tz, currency, locale) |
| accounts | `GET/POST /accounts`, `GET/PATCH /accounts/{id}`, `GET /accounts/{id}/balance` |
| categories | `GET/POST /categories`, `PATCH /categories/{id}`, `POST /categories/{id}/aliases` |
| merchants | `GET/POST /merchants`, aliases as above |
| counterparties | `GET/POST /counterparties`, `GET /counterparties/{id}/balance` |
| transactions | `GET /transactions` (filters: date range, type, account, category, merchant, counterparty, q), `POST /transactions` (typed `event_type` body, same command as WhatsApp), `GET /transactions/{id}`, `POST /transactions/{id}/reverse`, `POST /transactions/{id}/correct` |
| debts | `GET /debts?direction=receivable|payable`, `POST /debts/{id}/settle` |
| credit-cards | `GET/POST /credit-cards`, `GET /credit-cards/{id}/statements` |
| budgets | `GET/POST /budgets`, `PATCH/DELETE /budgets/{id}`, `GET /budgets/status` |
| goals | `GET/POST /goals`, `GET /goals/{id}/progress` |
| subscriptions | `GET/POST /subscriptions`, `PATCH /subscriptions/{id}`, `POST /subscriptions/{id}/cancel` |
| recurring | `GET/POST /recurring-rules`, `GET /recurring-occurrences`, `POST /recurring-occurrences/{id}/pay|skip` |
| loans | `GET/POST /loans`, `GET /loans/{id}/schedule`, `POST /loans/{id}/payments` |
| reports | `GET /reports/{type}?period=…` (summary, category, cash-flow, net-worth, health, …) |
| exports | `POST /exports` → `GET /exports/{id}` (signed URL) |
| ai | `GET /ai/usage` (own), `POST /ai/interpret` (dev/test: returns proposal + validation result, **never commits**) |

## 4. Admin (`/api/v1/admin`, admin guard + MFA + RBAC)
| Area | Endpoints |
|---|---|
| users | `GET /admin/users?q=`, `GET /admin/users/{id}`, `POST /admin/users/{id}/suspend|restore`, `GET /admin/users/{id}/usage|costs|errors` |
| ai | `GET /admin/ai/usage`, `GET/POST /admin/ai/models`, `GET/POST /admin/ai/prompts`, `POST /admin/ai/prompts/{id}/activate` (gated by eval), `POST /admin/ai/evals/run` |
| whatsapp | `GET /admin/whatsapp/health`, `/messages`, `/failures`, `/pricing` (rates CRUD) |
| costs | `GET /admin/costs/summary`, `/unit-economics`, `/infra` (CRUD) |
| system | `GET /admin/system/queues`, `/failed-jobs`, `POST /failed-jobs/{id}/retry`, `/health` (db, queue backlog, scheduler heartbeat) |
| config | CRUD for system categories/merchants/aliases, limits, feature flags, plans |
| compliance | `GET /admin/audit-logs`, `POST /admin/users/{id}/export|delete` (privacy requests) |

Every admin read of user financial data writes an `audit_logs` row.

## 5. Webhook payload handling contract
Request → signature check → `webhook_events` row → 200. Everything else is async. Return
non-2xx *only* for signature failures and malformed bodies (so Meta does not retry
things we cannot process); internal errors after persistence still return 200 and are
retried by our own reaper.

## 6. Documentation
OpenAPI 3.1 generated from route/request classes (e.g. Scribe or Scramble), published in
the repo and checked in CI for drift.
