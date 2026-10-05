# Design Review: Findings, Decisions, and Open Questions

Your spec asked me to challenge it (§120). This is that review. Each item follows
**Problem → Proposal → Tradeoff**. Items marked **🔴 SIGN-OFF** change the system
materially and need your explicit approval before Phase 1. The rest are
recommendations I will follow unless you object.

Confirmed stack: **Laravel, MySQL/MariaDB on Hostinger (see §H), Claude Haiku, Meta WhatsApp Cloud API
(direct)**, with a **future SaaS** model (multi-user, plans, admin).

---

## A. Platform constraints in the spec that need design changes

### A1. WhatsApp 24-hour customer-service window  🔴 SIGN-OFF
- **Problem.** Free-form messages (reports, replies, files) can only be sent within
  24 hours of the user's last inbound message. Everything proactive (EMI reminders,
  budget alerts, monthly report, subscription due) outside that window must use a
  **pre-approved template message**, which Meta approves in advance and typically bills
  per message. §68, §69 and §49 are therefore not "free text + cron".
- **Proposal.** (1) Treat notifications as *template-first*: a `notification_templates`
  registry mapping each notification type to a Meta template name + variables.
  (2) At send time, check `last_inbound_at`; inside the window send free-form,
  outside the window send the template. (3) Templates are submitted for approval
  before launch (lead time; some variable text restrictions apply). (4) Notifications
  default to **opt-in**, one per type, which also satisfies Meta's opt-in policy.
- **Tradeoff.** Reminder wording is constrained by template approval; each
  out-of-window reminder has a real cost, which is why the cost model (see
  `cost-model.md`) tracks it per message.

### A2. WhatsApp pricing and interactive limits are not stable  
- **Problem.** Pricing models have changed repeatedly; hard-coding is wrong (you said
  so in §49). Interactive limits also constrain UX: reply buttons are capped at **3**
  per message (title ≤ 20 chars), list messages at **10** rows. "Confirm / Edit / Cancel"
  fits; "choose a category from 25" does not.
- **Proposal.** Pricing lives in a table (`whatsapp_pricing_rates`), and actual billing
  data is captured from the **status webhooks** (which carry pricing category and
  billable flag) instead of estimating. Lists are paginated ("More…") or fall back to
  free-text disambiguation. I will verify current limits against Meta docs at
  implementation time; the numbers in these docs are design assumptions to re-check.

### A3. Voice notes need a separate speech-to-text vendor  🔴 SIGN-OFF (Phase 6)
- **Problem.** Claude models do not transcribe audio. Phase 6 needs an STT provider that
  handles Hindi/Hinglish well and accepts WhatsApp's Ogg/Opus.
- **Proposal.** `SpeechToTextProvider` interface (same pattern as `AIProvider`). Pick the
  vendor by running ~50 real Hinglish voice notes through two or three candidates.
  Not needed before Phase 6; flagged now because it adds a second vendor, API key, and
  privacy surface (audio leaves your system).

### A4. Webhooks are at-least-once and unordered
- **Problem.** Meta retries failed deliveries and may deliver messages out of order or
  concurrently. Two quick messages ("spent 500" then "groceries") can be processed in
  parallel, breaking the clarification flow, or in the wrong order.
- **Proposal.** (1) Persist the raw event first, then enqueue (DB is the durable
  source; Redis is not trusted to hold the only copy). (2) **Serialize processing per
  user** with a per-user mutex (`SELECT … FOR UPDATE` on the user's row inside the processing transaction, or a cache lock). (3) Order by Meta's
  message timestamp when draining a user's backlog. (4) A reaper job re-dispatches
  events stuck in `received`.

### A5. Media URLs expire fast
Media (images/voice) must be downloaded promptly after the webhook, using the bearer
token, then stored in object storage with an expiry. The media job is therefore
enqueued at webhook time, not lazily.

---

## B. Spec contradictions and redundancy

### B1. Duplicate ledger models  🔴 SIGN-OFF
- **Problem.** §35 puts `account_id`, `category_id` on `transactions`; §40 lists
  `transactions`, `transaction_entries`, `debts`, `receivables`, `account_types`,
  `subcategories`; §41 introduces `ledger_transactions` + `ledger_entries`. These
  overlap and two of them would drift out of sync.
- **Proposal.** One ledger: `ledger_transactions` (header) + `ledger_entries`
  (debit/credit lines). `account_id` and `category_id` live on **entries**.
  `debts`/`receivables` collapse into **per-counterparty ledger accounts** plus a thin
  `debt_records` table for due dates. `account_types` becomes enums in code.
  `subcategories` becomes `categories.parent_id`. Fewer tables, one source of truth.
- **Tradeoff.** Reporting reads entries rather than a flat table; solved with a
  few well-indexed SQL views/queries in the ReportService.

### B2. Categories as ledger accounts or as a dimension?  🔴 SIGN-OFF
- **Problem.** Textbook double-entry makes every expense category an account. With
  user-defined categories, aliases and sub-categories, that produces account explosion
  and makes re-categorisation a ledger operation.
- **Proposal.** Per user, one system `Expenses` account and one `Income` account;
  **category is a dimension (`category_id`) on the entry**. Account-level integrity
  (debits = credits, balances) is unaffected, and re-categorisation is a metadata
  correction with an audit record rather than a reversal.
- **Tradeoff.** Not a pure chart-of-accounts; you cannot get a P&L per category from
  balances alone, only from entries (which is what we query anyway).

### B3. Idempotency key scope
§36/§37 say `unique(user_id, whatsapp_message_id)`. At webhook time the user is not
yet resolved (first-ever message), and Meta message IDs are globally unique.
**Proposal:** `unique(wa_message_id)` on `whatsapp_messages`, and
`unique(idempotency_key)` on `ledger_transactions` where
`key = {wa_message_id}:{item_index}` (one voice note can contain several expenses).

### B4. "subscriptions" is overloaded
It means *the user's Netflix* in §17 and *SaaS billing* in the SaaS direction. Naming:
- Finance domain: `subscriptions` (user's recurring services).
- SaaS billing: `plans`, `billing_subscriptions`, `usage_quotas`.

### B5. Two phase plans (§106 and §122)
§106 has 5 product phases; §122 has 9 engineering phases (0–8). **I follow §122**
and map §106 into it inside `roadmap.md`.

### B6. Two example JSON shapes (§3 vs §8)
§8's envelope wins (nested `transaction`, `missing_fields`, `requires_confirmation`).
See `ai.md`.

---

## C. Accounting corrections

### C1. Store money as integer minor units  🔴 SIGN-OFF
- You allow `DECIMAL(19,4)` *or* minor units. **I recommend `BIGINT` minor units +
  ISO currency code** via a `Money` value object (e.g. `brick/money`). Exact
  arithmetic, trivial invariants (`SUM(debit) = SUM(credit)` is integer equality), and
  correct for currencies with 0 or 3 decimals (JPY, KWD). Exchange rates are
  `DECIMAL(20,10)` and the rounding mode is explicit (half-even for conversion;
  splits allocate remainders deterministically, see `ledger.md`).
- **Tradeoff.** Slightly less readable raw SQL (`25000` = ₹250.00); add a view for
  admin.

### C2. Balances are derived, not stored
A mutable `balance` column is the classic source of drift. **Proposal:** account
balances are `SUM` over entries (indexed on `account_id`). If measured load requires,
add a cache maintained **inside the same DB transaction** and verified nightly by a
reconciliation job. Not in MVP.

### C3. Ledger is append-only
No `UPDATE`/`DELETE` of entries. Enforced by model/DB guards plus nightly verification (see §H for what MySQL can and cannot enforce).
Corrections = *void + repost* linked by `corrects_id`; undo = *reversal transaction*
linked by `reversal_of_id`. `SUM(debit) = SUM(credit)` is enforced by a header CHECK plus
verify-before-commit (no deferred triggers on MySQL; see §H).

### C4. Recurring "expected" items are not ledger entries
Schedules produce `recurring_occurrences` (expected → due → paid/skipped/cancelled).
**Only a confirmed payment creates a ledger transaction.** A user message "Netflix 649"
near an expected occurrence is *matched* to it instead of creating a duplicate.

### C5. Ambiguity rules the AI must not decide
- *"Rahul gave me 2000"*: with an open receivable from Rahul → repayment. Without one →
  **ask** (borrowed vs. gift vs. repayment).
- *"transfer 50000 to Rahul"*: never a bank transfer. Ask: lent / gift / expense?
  Reply always states "recorded", never "sent" (§44).
- *"paid 12000 HDFC card"* → credit-card payment transfer, never an expense (§11, §100).
- Loan EMI → principal portion reduces loan liability, interest portion is an expense.

---

## D. AI-layer corrections

### D1. LLM self-reported confidence is poorly calibrated
- **Problem.** A model saying `0.97` is not a probability. Thresholds built only on
  it will silently let wrong records through.
- **Proposal.** The decision to auto-commit uses a **composite risk score** the
  *application* computes: entity-resolution success, amount cross-check, intent risk
  class, amount size vs. user history, plus the model's confidence as one input.
  Thresholds are configurable (§43).

### D2. Cross-check the amount against the raw text
Deterministic `AmountNormalizer` extracts numbers from the message ("2k", "2 lakh",
"1.5L", "₹1,200", Hindi/Devanagari numerals, spoken numbers after STT). If the model's
amount disagrees with every number found, the proposal is routed to clarification
instead of committed. This is cheap and catches most extraction errors.

### D3. The model returns a *date spec*, the server resolves the date
LLMs are bad at calendars. The model returns `{kind: "relative", offset_days: -1}` or
an explicit ISO date; `DateResolver` resolves it in the user's timezone (§59, §113).

### D4. The model never references database IDs
It returns *names* ("HDFC credit card", "sabji"). The server resolves them through
aliases/fuzzy matching; hallucinated or unknown entities become "unresolved" and
trigger a question or an explicit create-confirmation, never a silent insert (§9 in
test list: "AI hallucinated category").

### D5. Reports are rendered from templates, not by the LLM
Numbers are computed in SQL and formatted by deterministic WhatsApp templates. This is
cheaper (zero tokens), faster, and cannot hallucinate. The LLM is used only for
free-form explanation/insights, and its output is **post-checked** so every number in
it must appear in the supplied data. (§103, §61)

### D6. Haiku-first, router-ready
You specified Haiku. I build the **router and `AIProvider` abstraction from day one**
but ship with Haiku as the only enabled tier, plus an optional, config-flagged
**escalation to Sonnet** on validation failure or low composite score. Model IDs are
config/DB values, never constants. Verify current model IDs and prompt-cache minimums
at implementation (a short system prompt may fall under the minimum cacheable size, in
which case caching simply won't apply; this does not affect correctness).

### D7. Deterministic fast-path (Phase 8 optimisation, not MVP)
Patterns like `500 petrol` / `spent 120 vegetables` can be parsed without an LLM using
the alias dictionary. This could remove a large share of AI cost but adds a rule parser
that must be kept accurate. Build the pipeline with a `Preprocessor` stage so it slots in
later; do not build it first.

---

## E. SaaS / multi-user readiness (new, from your latest note)

### E1. Tenancy model  🔴 SIGN-OFF
- **Proposal.** *Row-level scoping by `user_id`* on every user-owned table; **no
  separate tenant/workspace layer yet.** Add stricter DB-level isolation later if needed (MySQL has no RLS).
  A `workspace_id` can be added when "household/shared finances" becomes a requirement;
  designing for it now is premature.
- Keep **end users** (`users`, authenticated by WhatsApp number) strictly separate from
  **staff** (`admins`, email + MFA, roles via spatie/laravel-permission, separate guard).
- SaaS plumbing designed now but built late: `plans` (limits as JSON: messages/day,
  AI requests/month, feature flags), `billing_subscriptions`, `usage_quotas`, an
  `EntitlementService` the message pipeline consults. Payment provider (Razorpay/Stripe)
  is a Phase 8 decision.

### E2. Gate onboarding at launch
Every new user costs real money (WhatsApp + AI). Start with **invite codes /
allowlist**, per-user daily AI and message budgets, and a global kill switch. Open
signup comes after quotas are proven.

### E3. WhatsApp number identity
The WhatsApp number is the only credential. Risks: number recycling, SIM swap, shared
phone. **Proposal:** store `wa_id` with an encrypted value plus a HMAC **blind index**
for lookup; offer an optional **PIN step-up** for high-risk actions (export all data,
delete account/history, very large transactions). Default off in MVP, schema ready.

### E4. Admin panel
**Proposal: Filament** (Laravel-native admin framework) instead of a custom Vue SPA.
Users, costs, queues, prompts, feature flags and config become mostly declarative.
Tradeoff: less visual freedom; large time saving. A bespoke SPA remains possible later
over the same API.

---

## F. Compliance and operations (flag, not legal advice)

- **India DPDP Act 2023** (consent, purpose limitation, erasure) and Meta's Business
  Messaging policies apply. Consent capture is built in (§94). **Retention periods in
  `security.md` are proposed defaults and need legal review**, especially "delete my
  account" vs. any statutory retention of financial records (§93).
- **Meta prerequisites** (account setup, not code): a Meta Business account, business
  verification, a dedicated phone number registered with the Cloud API (it cannot also
  be used in the normal WhatsApp app), a **permanent System User token** (not the
  24-hour temporary token), and template approvals. These have lead times; start early.
- **Disclaimer.** The system *records* financial events; it never moves money or gives
  regulated financial advice (§44). "Can I afford 10k?" returns deterministic numbers
  plus a neutral statement.

---

## G. Decisions I need from you (🔴 summary)

| # | Decision | My recommendation |
|---|---|---|
| A1 | Notifications opt-in, template-first | Yes |
| A3 | STT vendor selection deferred to Phase 6 | Yes, bake-off then |
| B1 | Single ledger model (drop duplicate tables) | Yes |
| B2 | Category as entry dimension, not account | Yes |
| C1 | Integer minor units instead of DECIMAL | Yes |
| E1 | `user_id` scoping, no tenant layer yet | Yes |
| E3 | Optional PIN step-up, schema-ready, off by default | Yes |
| E4 | Filament admin instead of custom Vue SPA | Yes |
| H | MySQL/MariaDB + database queue on Hostinger (§H changes) | Decided by you; plan type still to confirm |
| D6 | Haiku-only at launch, Sonnet escalation behind a flag | Yes |

If you agree to the table, I proceed to Phase 1 per `roadmap.md`. If you disagree with
any row, tell me which and I will revise the affected docs first.

---

## H. Hosting target: Hostinger subdomain + MySQL  (supersedes earlier PostgreSQL/Redis assumptions)

You told me the app will run on a Hostinger subdomain with a MySQL database. That is a real
change, so here is what it breaks and how the design adapts. **Which Hostinger plan** you have
(shared/Business vs. Cloud vs. VPS) still matters; I assumed the most restrictive case
(shared hosting) so the design works on any of them. Please confirm the plan, PHP version
(Laravel 13 needs PHP ≥ 8.3), whether you have SSH + Composer, and whether the DB is MySQL 8 or MariaDB.

| Earlier assumption | Problem on shared MySQL | Adaptation | Tradeoff |
|---|---|---|---|
| Postgres deferred constraint trigger enforces `ΣD = ΣC` | MySQL has no deferred triggers; shared hosts often deny `TRIGGER` privilege | Header `CHECK (debit_total = credit_total)` + **verify-before-commit** in `LedgerService` + nightly re-check + per-transaction `entries_hash` for tamper evidence; immutability triggers installed only if privileges allow, otherwise model-level guard | Weaker than Postgres against someone with raw DB access; correctness is still guaranteed for all app writes and detected nightly otherwise |
| `jsonb`, partial/functional unique indexes, RLS | Not available/portable (MySQL vs MariaDB differ) | `JSON` columns used only as opaque blobs (never queried inside); system categories/merchants are **copied per user** at setup so uniqueness is a plain `UNIQUE(user_id, parent_id, name)` | Updates to default categories don't propagate automatically |
| UUIDv7 ids | Poor fit for MySQL indexes | **ULID** (`CHAR(26)`, time-ordered) via Laravel `HasUlids`; `BIGINT` for entries/logs | none material |
| Redis + Horizon workers | Shared hosting has no Redis and no long-running daemons | `database` queue/cache/session drivers. Webhook dispatches `->afterResponse()` for near-instant processing, and **cron every minute** runs `queue:work --stop-when-empty --max-time=55` plus the scheduler as a safety net | Worst-case latency ≈ 60 s if `afterResponse` is not supported by the host's PHP handler (verify on the real host). Throughput is fine for one user |
| Advisory locks / `WithoutOverlapping` | | Per-user mutex via `SELECT … FOR UPDATE` on the `users` row inside the processing transaction | none material |
| Managed PITR backups | Typically not available on shared plans | Host backups **plus** our own scheduled `mysqldump` (encrypted, shipped off-host); quarterly restore drill kept | RPO is the dump interval (e.g. hourly/daily), not minutes |
| Postgres-only features in tests | | CI runs tests on **MySQL 8 and MariaDB 10.11**; local sandbox uses MariaDB | |

Other hosting facts to verify on your plan (I cannot check them from here): subdomain document
root can point to `public/`; outbound HTTPS to `graph.facebook.com` and `api.anthropic.com` is
allowed; PHP `max_execution_time` ≥ 60 s; cron minimum interval; free SSL on the subdomain
(Meta requires HTTPS); `.env` and `storage/` are not web-accessible.
