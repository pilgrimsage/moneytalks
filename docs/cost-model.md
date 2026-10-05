# Cost Architecture

Goal: know, per user and per message, what AI, WhatsApp and infrastructure cost, and the
resulting unit economics, **without hard-coded prices**.

## 1. AI cost

*Implemented in M5:* `ai_requests`, `ai_model_prices`, `CostCalculator`, `php artisan moneytalks:ai:usage` (by day/model/outcome, cost per recorded transaction). Not yet: rollup tables, per-plan budgets, admin dashboard (M11).

**Capture (every call, success or failure)**, via the recording wrapper around
`AIProvider`:

`ai_requests`: provider, model, request_type, prompt_template_id, input/output/cached
tokens, latency, status, `estimated_cost_micros` + currency, `user_id`,
`whatsapp_message_id`.

**Pricing** lives in `ai_model_prices` (per-million-token rates for input, output, cache
read, cache write, with `effective_from`). Cost = tokens × the rate effective at request
time, computed from the provider's returned usage numbers, not from our own token
estimate. Changing prices = inserting a row.

**Aggregation:** nightly `ai_usage_daily` (day × user × model × request_type) → cost per
user / message / model / feature / day / month.

**Controls:** per-user daily and monthly AI budget from the plan (`EntitlementService`),
global daily budget alarm, kill switch, admin routing config to move traffic to cheaper
tiers.

*Voice and receipts (M10):* receipt photos are priced like any other call (tokens from the provider's usage, `request_type = receipt_parser`). Transcription is **not yet priced**:
the STT vendor bills by audio minute, which needs its own rate table; until then watch the vendor's dashboard. `WHATSAPP_MEDIA_PER_USER_DAILY` (default 30) bounds the exposure.

## 2. WhatsApp cost

Meta's pricing structure and rates change; we do not encode them. Instead:

1. **Actuals:** the status webhooks include pricing information (category, billable flag)
   for outbound messages. Store `pricing_category`, `billable` on `whatsapp_messages`.
2. **Rates:** `whatsapp_pricing_rates` (market × category × rate × currency ×
   `effective_from`), maintained by an admin (or CSV import from Meta's published rate
   card). `cost = rate(category, market, at sent_at)` for billable rows.
3. **Reconciliation:** monthly, compare our computed total to Meta's invoice/billing
   analytics; the delta is shown in admin.
4. **Provider abstraction:** a `MessagingCostProvider` interface so another provider's
   billing can be mapped later.

Cost levers visible to product: templates sent outside the 24h window (the
billable ones), notification volume per user, whether reports are sent proactively.

## 3. Infrastructure cost
`infra_cost_entries` (monthly, manual or CSV: hosting, DB, storage, STT vendor,
email/SMS, monitoring). Allocated to users by active-user share (default) or usage.

## 4. Rollups and unit economics
`cost_daily_rollups` per user per day: `ai_cost, whatsapp_cost, msg_count, txn_count`.

```
variable_cost/user/month = AI + WhatsApp (+ STT)
infra/user/month         = infra_total / active_users
gross_contribution       = subscription_revenue − (AI + WhatsApp + infra)
cost_per_transaction     = (AI + WhatsApp) / transactions_created
cost_per_conversation    = WhatsApp cost / conversations (24h sessions)
```
"Active user" = a user with ≥1 inbound message in the period (definition configurable).
Revenue comes from `billing_subscriptions` once SaaS billing exists; until then it is 0
and the dashboard shows pure cost.

## 5. Admin dashboard (Filament)
Total/active users · messages today/month · AI requests/tokens/cost · WhatsApp
messages/cost (by category) · infra cost · total cost · cost per active user / per
transaction / per conversation · average AI, WhatsApp and infra cost per user per month ·
top-N costliest users · cost by model, feature and day · budget-burn alerts.

## 6. Where the savings are (to measure, not assume)
Deterministic reports (0 tokens) · alias hints instead of big prompts · Haiku-default routing ·
short prompts + prompt caching where applicable · commands/buttons bypassing AI · the rule
fast-path (Phase 8) · keeping notifications opt-in and within the free-form window where
possible. The eval suite reports **cost per case** so any prompt change shows its cost
impact before promotion.

## 7. Configuration
```
COST_BASE_CURRENCY=INR
COST_FX_USD_INR=                # or from exchange_rates
AI_DAILY_BUDGET_GLOBAL=
```
