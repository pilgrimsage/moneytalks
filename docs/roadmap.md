# MVP Scope and Implementation Plan

## Status at a glance (read this first)

| Milestone | What | State |
|---|---|---|
| M0-M2 | Design docs, scaffold, identity and reference data | ✅ done |
| M3 | Double-entry ledger (append-only, verified, idempotent) | ✅ done |
| M4 | WhatsApp Cloud API (signed webhook, idempotent, 24h window) | ✅ done |
| M5-M6 | AI interpretation, follow-ups, Confirm taps, duplicates, undo, corrections | ✅ done |
| M7 | Questions, reports and CSV export | ✅ done |
| M8 | Debts (lend/borrow/repay), split expenses, credit-card bill payments | ✅ done (card statements/limits deferred) |
| M9 | Budgets + alerts, recurring payments/subscriptions with reminders, goals, EMI loans, monthly summary | ✅ done |
| M10 | Voice notes (pluggable STT) and receipt photos (Claude vision) | ✅ done (needs your STT vendor key for voice) |
| M11 | Health checks, cost report, AI kill switch and budget cap | ✅ done (console + `/health`, no admin UI) |
| M12 | Security review and fixes, encrypted backups + restore drill, privacy export/erase, retention, runbooks | ✅ done for personal mode |

**Everything above is built and tested against fakes and mocked HTTP only.** It has not yet been run against the real Meta and Anthropic services:
the first live session is the remaining risk (see README "Before you trust it with real money").

**Not built (deliberately deferred):** credit-card statements, limits and due dates; "someone else paid" splits; notification template messages for
reminders outside the 24-hour window; Excel/PDF export; admin UI, RBAC/MFA; plans/billing and more than one user without the allow-list; consent/onboarding flow
for other users; statement import and reconciliation; multi-currency; investments; STT vendor bake-off and transcription cost pricing; PIN step-up.

Follows the §122 engineering sequence (Phases 0–8). §106's product phases are mapped in.
Each milestone is independently testable and ends with: migration → service → tests →
(WhatsApp/AI integration where relevant) → observability → docs → security & performance
check.

## 1. MVP definition ("walking skeleton", end of Milestone 6)

A real user on WhatsApp can: consent and onboard; record **expenses, income and
transfers** in English/Hindi/Hinglish; be asked a clarifying question and answer it;
undo and correct; be protected from duplicates and webhook replays; see exact reports. All
through the ledger, with AI cost tracked per request.

## 2. Explicitly NOT in the MVP

| Deferred | Phase | Why |
|---|---|---|
| Voice notes (needs STT vendor) | 6 | extra vendor + privacy surface |
| Receipt/image OCR | 6 | needs confirm UX and storage lifecycle |
| Statement import, reconciliation, bank/UPI integrations | later | high complexity; duplicate detection must be mature first |
| Multi-currency behaviour (schema ready) | 8 | INR first |
| Investments, forecasting, advanced analytics | 8 | |
| Excel/PDF export (CSV done in M7) | 4/7 | |
| Sonnet escalation, rule-based fast-path | 8 | optimise after measuring |
| Paid billing / payment provider | 8 | gate with invites first |
| Notifications & templates | 5 | require Meta template approval (start the approvals early) |
| Custom admin SPA | – | Filament instead |
| Household/shared accounts, workspaces | – | not required yet |

## 3. Milestones

### Phase 0: Architecture (this)
**M0** Docs reviewed; 🔴 decisions in `decisions.md` signed off. Meta account/number setup
and template drafting started in parallel (non-code, long lead).

### Phase 1: Foundation + Database + Ledger
- **M1 Scaffold.** Laravel app, Docker Compose, CI (Pint, Larastan, Pest on MySQL +
  MariaDB services), `.env.example`, README. *Done when:* CI green on an empty feature test.
- **M2 Identity & reference data. ✅ implemented** (users/settings, categories, merchants, counterparties,
  aliases, `Money`, `EntityResolver`, `moneytalks:user:create`; ledger accounts and consents moved to M3/later). `users`, `user_settings`, `consents`, accounts,
  categories (+ seed hierarchy & aliases incl. Hinglish), merchants, counterparties,
  `Money` value object. *Done when:* seeders idempotent; entity resolver unit-tested.
- **M3 Ledger core. ✅ implemented** (accounts, posting rules for expense/income/transfer/opening
  balance, reversal, correction, verifier, audit log, property + concurrency tests). `ledger_*` tables, CHECK + verify-before-commit balancing (no deferred triggers on MySQL), `LedgerService`,
  `PostingRules` for expense/income/transfer/reversal/correction, audit logs, idempotency
  key. *Done when:* DB-constraint tests + property tests pass.

### Phase 2: Meta WhatsApp Cloud API
- **M4 Provider + webhook. ✅ implemented** (placeholder handler; AI arrives in M5). `WhatsAppProvider`/`MetaWhatsAppProvider`, handshake,
  signature check, `webhook_events`, `whatsapp_messages` dedupe, per-user queue lock,
  outbound service with window check, status webhooks. *Done when:* signed-payload
  simulator → echo bot works against a Meta test number; duplicate delivery test passes.

### Phase 3: AI Transaction Parser
- **M5 Interpretation. ✅ implemented** (see docs/ai.md section 0 for the deliberate limits; no conversation state until M6). `AIProvider` + `AnthropicProvider`, recording wrapper + cost
  tables, `prompt_templates`, preprocessing, `AmountNormalizer`, `DateResolver`,
  validation/risk scoring, expense/income/transfer end-to-end, eval harness v1 (§80
  cases). *Done when:* eval gate passes; E2E "spent 250 on vegetables" works live.
- **M6 Conversation & safety. ✅ implemented** (pending state + TTL, follow-ups, Confirm taps, duplicates, undo, corrections; onboarding/consent skipped in personal mode). Pending state + TTL, clarification, duplicate-content
  detection, undo, corrections, onboarding/consent, help/commands. **← MVP complete.**

### Phase 4: Reports
- **M7 Query & reports. ✅ implemented** (period resolver, SQL-only `ReportService`, `QueryPlanner`, deterministic formatters, CSV export sent as a WhatsApp document; debts/budgets/subscriptions questions are declined honestly until M8/M9). Whitelisted `QueryBuilder`, `query`/`search`/`report`
  intents, WhatsApp formatters (daily/weekly/monthly/category/etc.), CSV export,
  budget-less summaries, net worth/balances. *Done when:* numbers verified against
  independent SQL in tests; no LLM in the number path.

### Phase 5: Debts / Credit Cards / Recurring
- **M8 Debts, splits, cards. ✅ partly implemented** (lend/borrow/repayments with FIFO settlement, split expenses, card bill payments, "who owes" queries, verifier check; **not yet:** card statements/limits/due dates and "someone else paid" splits, which move to M9 with reminders). Counterparty accounts, `debt_records/settlements`, lend/
  borrow/repay, split expenses, credit cards + statements + payments.
- **M9 Recurring & planning. ✅ implemented** (budgets + alerts, recurring payments/subscriptions with reminders, savings goals, EMI loans, monthly closing summary). **Not yet:** credit-card statements/limits/due dates, notification template registry (reminders outside the 24-hour window are recorded as failed), sinking funds, loan prepayment/override of the lender's interest. Recurrence engine, occurrences, subscriptions, loans/EMI
  (amortisation), budgets + threshold alerts, goals, notifications (template registry,
  opt-in prefs), monthly closing.

### Phase 6: Voice / Receipt
- **M10 ✅ implemented** (voice notes via a pluggable `SpeechToTextProvider`, receipt photos via Claude vision, always confirmed; **not yet:** STT vendor bake-off with real audio, cost pricing for transcription, personal vocabulary learning, statement documents). Original scope: STT bake-off → `SpeechToTextProvider`; media pipeline hardening; receipt vision
  with mandatory confirmation; personal vocabulary learning.

### Phase 7: Admin / Cost / Observability
- **M11 ✅ implemented in personal-mode form** (console commands and a token-protected health endpoint instead of a Filament admin: `moneytalks:health`, `GET /health`, `moneytalks:cost:report`, AI kill switch and global daily budget; **not yet:** admin UI, RBAC/MFA, prompt/model management UI, per-user plans and budgets). Original scope: Filament admin (RBAC, MFA, audit), cost dashboards and unit economics,
  prompt/model management with eval gate, queue/system health, metrics.

### Phase 8: Security / Performance / Production
- **M12 ✅ implemented for personal mode** (independent security review with fixes, encrypted backups + restore drill, privacy export/erasure (console), retention purge, runbooks, trusted-proxy and media hardening; **not yet:** PIN step-up (no chat path to destructive actions exists), load tests beyond the concurrency suite, plans/billing, Sonnet escalation, rule fast-path, reconciliation, statement import, multi-currency). Original scope: Security review & pen-test pass, PIN step-up, privacy flows (export/delete),
  retention jobs, load tests, backup/restore drill, runbooks, quotas/plans/billing
  groundwork, optional: Sonnet escalation, rule fast-path, reconciliation, statement
  import, multi-currency.

## 4. Mapping from §106

| §106 | Milestones |
|---|---|
| Phase 1 Foundation | M1–M6 |
| Phase 2 Financial intelligence | M6–M8 (+ reports M7) |
| Phase 3 Recurring finance | M9 |
| Phase 4 AI expansion | M10, M12 |
| Phase 5 Advanced finance | M12+ |

## 5. Risks to watch
1. **Meta onboarding lead time** (verification, number, templates): start now.
2. **Hinglish accuracy on Haiku**: the eval suite is built before the features it
   judges; collect real (consented) messages to grow it.
3. **Cost at scale**: tracked from M5; routing and fast-path are optimisations driven by
   real numbers.
4. **Notification economics**: template messages out of window cost money; per-plan limits.
5. **Legal/regulatory** (DPDP, Meta policy, retention vs. erasure): review before opening
   signups.

## 6. Personal mode (current target)

The first deployment is a single user (the author), so the 24-hour window is not a concern as
long as they message the bot daily. Scope adjustments (schema keeps `user_id` everywhere so SaaS
remains possible):

| Deferred | Replaced by |
|---|---|
| Invite codes, plans, billing, quotas | `ALLOWED_WA_IDS` allow-list in config; other senders are ignored with no AI call |
| Consent flow (M6) | Not needed for self-use; reinstated before any other user |
| Filament admin (M11) | Console command / simple page for cost and queue health |
| Notification template registry, PIN step-up | Later; the outbound service still records `outside_window_no_template` failures instead of dropping them |

Meta's Cloud API **test number** avoids business verification and template approval; verify its
current limits before relying on it.
