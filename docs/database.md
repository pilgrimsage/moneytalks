# Database Design (MySQL 8 / MariaDB 10.6+)

Conventions: `id` = ULID (`CHAR(26)`, time-ordered) except high-volume log tables (`BIGINT` auto-increment);
engine InnoDB, charset `utf8mb4`, collation `utf8mb4_unicode_ci` (case/accent-insensitive alias matching);
`jsonb` below means a `JSON` column used as an opaque blob (never queried inside);
money = `BIGINT *_minor` + `CHAR(3) currency`; timestamps `DATETIME(6)` in UTC; user-local
date as `DATE`; `user_id` on every user-owned table and **first** in composite indexes;
soft deletes only where noted (ledger rows are never deleted).

## 1. ER diagram: core ledger

```mermaid
erDiagram
    users ||--o{ ledger_accounts : owns
    users ||--o{ ledger_transactions : owns
    users ||--o{ categories : "defines (custom)"
    users ||--o{ counterparties : knows
    users ||--o{ merchants : "defines (custom)"
    users ||--|| user_settings : has
    users ||--o{ consents : gave

    ledger_transactions ||--|{ ledger_entries : "has (2+)"
    ledger_accounts ||--o{ ledger_entries : "debited/credited"
    categories ||--o{ ledger_entries : "dimension"
    categories ||--o{ categories : parent
    merchants ||--o{ ledger_transactions : at
    counterparties ||--o{ ledger_transactions : with
    counterparties ||--o{ ledger_accounts : "receivable/payable"
    ledger_transactions ||--o| ledger_transactions : "reversal_of / corrects"
    whatsapp_messages ||--o{ ledger_transactions : "source"
    ai_requests ||--o{ ledger_transactions : "interpreted by"

    ledger_accounts ||--o| credit_cards : "is"
    credit_cards ||--o{ credit_card_statements : has
    ledger_accounts ||--o| loans : "is"
    loans ||--o{ loan_installments : schedule
    counterparties ||--o{ debt_records : "owes/owed"
    debt_records ||--o{ debt_settlements : "settled by"
    ledger_transactions ||--o{ debt_settlements : "via"

    ledger_transactions {
      char26 id PK
      char26 user_id FK
      text type
      text status
      date occurred_on
      timestamptz occurred_at
      text description
      text payment_method
      text source
      char26 merchant_id FK
      char26 counterparty_id FK
      char26 wa_message_id FK
      text idempotency_key UK
      numeric confidence
      char26 reversal_of_id FK
      char26 corrects_id FK
      char26 reversed_by_id FK
      char3 base_currency
      bigint base_amount_minor
      numeric exchange_rate
      bigint debit_total_minor
      bigint credit_total_minor
      char64 entries_hash
    }
    ledger_entries {
      bigint id PK
      char26 transaction_id FK
      char26 user_id FK
      char26 account_id FK
      char1 direction
      bigint amount_minor
      char3 currency
      char26 category_id FK
      int position
    }
    ledger_accounts {
      char26 id PK
      char26 user_id FK
      text kind
      text subtype
      text name
      char3 currency
      char26 counterparty_id FK
      bool is_system
      bool is_default
      text status
      jsonb meta
    }
```

## 2. ER diagram: planning, conversation, ops, SaaS

```mermaid
erDiagram
    users ||--o{ conversations : has
    conversations ||--o| conversation_states : "pending state"
    conversations ||--o{ whatsapp_messages : contains
    whatsapp_messages ||--o| media_assets : "has media"
    webhook_events ||--o| whatsapp_messages : produces
    users ||--o{ ai_requests : triggers
    prompt_templates ||--o{ ai_requests : "versioned by"
    ai_model_prices ||--o{ ai_requests : "priced by"

    users ||--o{ recurring_rules : has
    recurring_rules ||--o{ recurring_occurrences : generates
    recurring_occurrences }o--o| ledger_transactions : "paid by"
    recurring_rules ||--o| subscriptions : "schedule of"
    recurring_rules ||--o| loans : "schedule of"

    users ||--o{ budgets : sets
    users ||--o{ goals : sets
    goals }o--o| ledger_accounts : "fund account"
    users ||--o{ user_aliases : "vocabulary"
    users ||--o{ notifications : receives
    users ||--o{ reconciliations : runs
    users ||--o{ import_batches : uploads
    import_batches ||--o{ import_rows : contains

    plans ||--o{ billing_subscriptions : "subscribed to"
    users ||--o{ billing_subscriptions : pays
    users ||--o{ usage_quotas : "metered by"
    admins ||--o{ audit_logs : acts
    users ||--o{ audit_logs : "subject"
```

## 3. Tables

Legend: **PK** primary key, **UK** unique, **FK**, `idx` index. Columns shown are the
meaningful ones; `created_at/updated_at` implied.

### 3.1 Identity and settings
**users**: `id PK`, `wa_id_enc` (encrypted), `wa_id_bidx UK` (HMAC blind index),
`name`, `timezone` (default `Asia/Kolkata`), `base_currency` (`INR`), `locale`
(`en-IN`), `language`, `status` (`pending_consent|onboarding|active|suspended|deleted`),
`onboarding_step`, `last_inbound_at` (24h-window logic), `deleted_at`.

**user_settings**: `user_id PK/FK`, `default_account_id`, `notification_prefs jsonb`
(per type on/off), `confirm_threshold_minor`, `duplicate_window_seconds`,
`pin_hash` (nullable), `feature_overrides jsonb`.

**consents**: `user_id`, `purpose` (`data_processing|ai|media|notifications`),
`version`, `granted_at`, `revoked_at`, `evidence_wa_message_id`.

### 3.2 Ledger (see `ledger.md`)
**ledger_accounts**: columns in ER above; `UK(user_id, kind, name)` for non-counterparty
accounts, `UK(user_id, counterparty_id, kind)` for receivable/payable; `idx(user_id, subtype)`.

**ledger_transactions**: `type` enum
(`expense,income,transfer,cc_payment,lend,borrow,repayment_in,repayment_out,split_expense,
refund,emi_payment,adjustment,opening_balance`), `status`
(`posted|reversed|voided`), `source`
(`whatsapp_text|whatsapp_voice|whatsapp_image|manual|import|system`),
`recurring_occurrence_id`, `import_row_id`, `ai_request_id`, `related_transaction_id`.
Indexes: `UK(idempotency_key)`, `CHECK(debit_total_minor = credit_total_minor)`, `idx(user_id, occurred_on DESC)`,
`idx(user_id, type, occurred_on)`, `idx(user_id, merchant_id, occurred_on)`,
`idx(wa_message_id)`, `idx(user_id, status)`.

**ledger_entries**: `idx(account_id, id)` (balances), `idx(user_id, category_id,
transaction_id)` (category reports), `idx(transaction_id)`. Immutability: model guard (+ triggers
if the host permits). Balance: header `CHECK` + verify-before-commit (`ledger.md` §2).

**categories**: `id`, `user_id` (system defaults are copied per user), `parent_id`, `kind`
(`expense|income`), `name`, `icon`, `is_active`, `sort`; `UK(user_id, parent_id, name)` (case-insensitive via collation).
**user_aliases**: `user_id`, `entity_type` (`category|merchant|account|counterparty`),
`entity_id`, `alias` (normalised), `locale`, `source` (`seed|user|learned`),
`use_count`; `UK(user_id, entity_type, alias)`. Seeded global aliases (sabji → Vegetables)
are copied lazily or read from a system scope.
**merchants**: `id`, `user_id` (copied per user), `name`, `default_category_id`.
**counterparties**: `id`, `user_id`, `name`, `phone_enc` (nullable), `status`.

### 3.3 Debts, cards, loans
**debt_records**: `id`, `user_id`, `counterparty_id`, `direction`
(`receivable|payable`), `opened_transaction_id`, `original_minor`, `due_on`,
`status` (`open|partial|settled|written_off`). Remaining = `original − Σ settlements`.
**debt_settlements**: `debt_record_id`, `transaction_id`, `amount_minor`.
**credit_cards**: `account_id PK/FK`, `issuer`, `nickname`, `last4 CHAR(4)`,
`credit_limit_minor`, `statement_day`, `due_day`, `grace_days`. *No PAN, no CVV, ever.*
**credit_card_statements**: `card_id`, `period_start`, `period_end`, `statement_date`,
`due_date`, `total_due_minor`, `min_due_minor`, `paid_minor`, `status`.
**loans**: `account_id PK/FK`, `name`, `principal_minor`, `annual_rate_bps`,
`tenure_months`, `emi_minor`, `start_date`, `end_date`, `due_day`,
`payment_account_id`, `recurring_rule_id`, `status`.
**loan_installments**: `loan_id`, `seq`, `due_on`, `emi_minor`, `principal_minor`,
`interest_minor`, `status` (`expected|due|paid|skipped`), `transaction_id`.

### 3.4 Recurring, subscriptions, budgets, goals
**recurring_rules**: `user_id`, `kind`
(`subscription|emi|salary|rent|insurance|sip|bill|other`), `event_type`, `template jsonb`
(amount, accounts, category, merchant), `freq` (`daily|weekly|monthly|yearly`),
`interval`, `by_month_day`, `by_weekday`, `start_on`, `end_on`, `timezone`, `status`
(`active|paused|cancelled`), `next_due_on`.
**recurring_occurrences**: `rule_id`, `due_on`, `status`
(`expected|due|paid|skipped|cancelled`), `transaction_id`, `reminded_at`;
`UK(rule_id, due_on)`.
**subscriptions**: `user_id`, `name`, `merchant_id`, `recurring_rule_id`, `amount_minor`,
`billing_frequency`, `next_billing_on`, `payment_account_id`, `category_id`, `status`.
**budgets**: `user_id`, `scope` (`overall|category|account|goal`), `category_id`,
`account_id`, `goal_id`, `amount_minor`, `period` (`monthly`), `alert_thresholds`
(`{80,90,100}`), `last_alert_level_by_period`.
**goals**: `user_id`, `name`, `target_minor`, `deadline`, `fund_account_id`, `status`.
Progress = fund account balance; required monthly saving computed.

### 3.5 Messaging and conversation
**webhook_events** (implemented): `id bigint`, `provider`, `event_hash UK` (sha256 of the raw body), `payload` (raw JSON),
`processing_started_at`, `attempts`, `error`
(retention-limited), `status` (`received|queued|processed|failed|ignored`),
`attempts`, `error`, `received_at`, `processed_at`.
**whatsapp_messages** (implemented; no `conversation_id` yet; the 24h window uses `users.last_inbound_at`).
Body and payload are columns `text` / `payload` with Laravel `encrypted` casts; `dedupe_key UK` makes outbound
replies retry-safe; `in_reply_to`; `peer_bidx` (blind index of the other party's number, never the number);
`meta_timestamp`. Originally specified as: `id`, `user_id` (nullable until resolved), `conversation_id`,
`wa_message_id UK`, `direction`, `message_type`
(`text|audio|image|interactive|document|button|system|template`), `text_enc`,
`payload_enc jsonb`, `status`
(`received|queued|processing|processed|processing_failed|sent|delivered|read|failed`),
`pricing_category`, `billable`, `cost_minor`, `cost_currency`, `error`, `received_at`,
`processed_at`, `sent_at`. Index `(user_id, received_at DESC)`.
**conversations**: `user_id`, `window_expires_at`, `last_message_at`.
**conversation_states**: `conversation_id PK`, `pending_intent`, `pending_payload jsonb`,
`awaiting` (`field|confirmation|choice`), `expires_at` (TTL, default 10 min),
`context_refs jsonb` (last transaction id, last counterparty).
**media_assets**: `message_id`, `kind`, `mime`, `size`, `sha256`, `storage_key`,
`expires_at`, `deleted_at`.
**notifications**: `user_id`, `type`, `payload`, `scheduled_for`, `template_name`,
`channel_mode` (`free_form|template`), `status`, `whatsapp_message_id`, `dedupe_key UK`.

### 3.6 AI
**prompt_templates**: `name`, `version`, `provider`, `model`, `body`, `schema_version`,
`status` (`draft|active|retired`); `UK(name, version)`; one active per
`(name, provider)`.
**ai_requests**: `id bigint`, `user_id`, `whatsapp_message_id`, `provider`, `model`,
`request_type`, `prompt_template_id`, `input_tokens`, `output_tokens`, `cached_tokens`,
`total_tokens`, `estimated_cost_micros`, `currency`, `latency_ms`, `status`,
`validation_result`, `error_code`, `input_enc` / `output_enc` (retention-limited),
`created_at`. Indexes `(user_id, created_at)`, `(model, created_at)`,
`(request_type, created_at)`.
**ai_model_prices**: `provider`, `model`, `input_per_mtok`, `output_per_mtok`,
`cache_read_per_mtok`, `cache_write_per_mtok`, `currency`, `effective_from`.
**ai_usage_daily** (rollup): `day`, `user_id`, `model`, `request_type`, counts, tokens,
cost.
**ai_eval_runs**: `prompt_template_id`, `model`, `dataset_version`, `accuracy`,
`false_txn_rate`, `missing_field_rate`, `p50/p95_latency`, `cost`, `details jsonb`.

### 3.7 Cost, SaaS, admin, compliance
**whatsapp_pricing_rates**: `market`, `category`, `rate_minor`, `currency`,
`effective_from`. **infra_cost_entries**: `period`, `label`, `amount_minor`, `currency`.
**cost_daily_rollups**: `day`, `user_id`, `ai_cost`, `whatsapp_cost`, `msg_count`,
`txn_count`.
**plans**: `code`, `name`, `price_minor`, `currency`, `interval`, `limits jsonb`,
`features jsonb`. **billing_subscriptions**: `user_id`, `plan_id`, `status`,
`provider`, `provider_ref`, `current_period_start/end`.
**usage_quotas**: `user_id`, `metric`, `period_start`, `used`, `limit`.
**admins**: `email UK`, `password`, `mfa_secret_enc`, `last_login_at` (+ roles/permissions).
**audit_logs**: `id bigint`, `actor_type` (`user|admin|system`), `actor_id`, `user_id`,
`action`, `subject_type`, `subject_id`, `before jsonb`, `after jsonb`, `reason`,
`correlation_id`, `ip`; append-only; `idx(user_id, created_at)`.
**exchange_rates**: `base`, `quote`, `rate numeric(20,10)`, `as_of`, `source`.
**reconciliations**: `account_id`, `stated_minor`, `expected_minor`, `diff_minor`,
`adjustment_transaction_id`, `status`.
**import_batches / import_rows**: statement import pipeline (Phase 8).
**data_export_requests**: `user_id`, `kind`, `status`, `storage_key`, `expires_at`.
**product_events**: `user_id`, `name`, `props` (non-sensitive), `created_at`.

## 4. Why these tables and not the full §40 list

| Spec table | Disposition |
|---|---|
| `account_types` | enum in code |
| `subcategories` | `categories.parent_id` |
| `transactions` + `transaction_entries` | `ledger_transactions` + `ledger_entries` |
| `debts`, `receivables` | counterparty ledger accounts + `debt_records` |
| `budget_categories` | `budgets` with nullable `category_id` |
| `messages` | `whatsapp_messages` |
| `ai_usage` | `ai_usage_daily` rollup from `ai_requests` |
| `recurring_transactions` | `recurring_rules` (naming) |

## 5. Retention hooks
`webhook_events.payload`, `whatsapp_messages.*_enc`, `ai_requests.input_enc/output_enc`
and `media_assets` carry expiry/purge timestamps and are cleaned by scheduled jobs.
Ledger, audit, and cost metadata are retained long-term (see `security.md`).

## 6. Scale notes
Index keys on `utf8mb4` are limited (keep indexed VARCHARs ≤ 191 chars or use prefix/hash columns, e.g. `event_hash CHAR(64)`).
Start unpartitioned. Revisit partitioning `ledger_entries`/`audit_logs`/`ai_requests`
by month when a table exceeds hundreds of millions of rows or vacuum/index cost shows
up in measurements, not before.
