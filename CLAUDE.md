# MoneyTalks: notes for contributors and coding agents

WhatsApp-first personal finance manager (Laravel 13, MySQL/MariaDB on Hostinger, Claude Haiku, Meta
Cloud API). Target host is shared-hosting-like: no Redis, no daemons; database queue + cron (`docs/deployment.md`). **Read `docs/README.md` first**; the design in `docs/` is the source of truth and
`docs/roadmap.md` lists the milestones. Do not claim a feature exists unless it is in the code.

## Non-negotiables
- The LLM only *proposes*; validated domain code writes. No LLM or AI-generated SQL touches the DB.
- Money is integer minor units + currency. Never float.
- The ledger is double-entry and append-only (see `docs/ledger.md`). Financial writes are one DB transaction.
- Every webhook/message is idempotent (`wa_message_id`, ledger `idempotency_key`).
- Every user-owned table has `user_id`. Never log tokens, secrets, card numbers or message bodies at info level.
- Tests run on real MySQL/MariaDB (MySQL locally; keep it MariaDB-compatible too), not SQLite. Keep SQL portable between them: no
  Postgres-only features, no JSON querying, no reliance on triggers or deferred constraints.

## Commands
- `./vendor/bin/pest` tests (includes `tests/Concurrency`, which spawns real parallel processes) · `./vendor/bin/pint` format
- Single test: `./vendor/bin/pest tests/Feature/Ledger --filter="name"` · suites: `--testsuite=Unit|Feature|Concurrency` · AI evals: `php artisan moneytalks:ai:eval`
- Console tooling lives in `app/Console/Commands` (`moneytalks:*`: `ledger:verify`, `health`, `simulate` WhatsApp, backup/restore, privacy export/erase); schedule is `routes/console.php`.
- Test layout: `Unit` is pure PHP (no framework/DB); `Feature` uses `RefreshDatabase`; `Concurrency` uses `DatabaseTruncation` (real commits, spawned workers). Shared helpers (`ledgerUser`, `account`, `command`, `rupees`) are in `tests/Pest.php`.
- Local setup: `cp .env.example .env && composer install && php artisan key:generate && php artisan migrate`. No Docker, no CI/CD: deployment is a manual pull on Hostinger (`docs/deployment.md`).
- Local DB: MySQL `moneytalks` / `moneytalks_test` as `root`/`root` (set in `.env` and `phpunit.xml`; change both if your MySQL differs).

## Architecture in one picture
```
WhatsApp message -> Webhook (save, answer 200) -> Job -> Understand -> Do -> Reply
                                                          |            |
                                              AI proposes a        Ledger posts it
                                              JSON guess, code     (or Reports read it)
                                              validates it
```
Four layers, one direction; each only calls the next:
1. **WhatsApp** (`Services/WhatsApp`): receive, send, media checks. Knows Meta, nothing about money.
2. **Understand** (`Services/AI`, `Services/Interpretation`): AI guess -> validated `Decision` (record / ask / confirm / refuse).
3. **Do** (`Domain/Ledger`, plus `Services/{Budgets,Goals,Loans,Recurring,Reporting}`): the only place money is written or read.
4. **Ops** (`Services/{Ops,Backup,Privacy,Closing}`, `Console/Commands`, `routes/console.php` cron): health, backups, monthly jobs.
Design docs per area are in `docs/` (`ledger.md`, `whatsapp.md`, `ai.md`, `testing.md`).

## Ledger rules of thumb (M3)
- Post only through `App\Domain\Ledger\LedgerService` (`post`, `reverse`, `correct`). Never write
  `ledger_*` tables directly and never update/delete them (models, DB triggers and the verifier all enforce this).
- New event types add a rule to `PostingRules` plus tests in `tests/Feature/Ledger`; keep `PostingRules` pure.
- `php artisan moneytalks:ledger:verify` must stay green; it runs nightly via the scheduler.

## WhatsApp rules of thumb (M4)
- The webhook controller only authenticates, persists, enqueues and answers 200. Work happens in `ProcessWebhookEvent`.
- All outbound goes through `OutboundMessageService` (window check, retries, `dedupe_key`). Never call the provider directly.
- Inbound handling must be safe to run twice for one message: use ledger idempotency keys and `reply:{wa_message_id}:{n}` reply keys.
- Never store content of messages from senders that are not allowed; never log message bodies or tokens.
- New vendor = new `WhatsAppProvider` implementation; nothing else may know Meta's wire format.

## AI rules of thumb (M5)
- All model calls go through `AIGateway` (routing, retry, `ai_requests` row, cost). Never call a provider directly from domain code.
- The model's output is a *proposal*. `ProposalValidator` is the only thing that turns it into a `Decision`; only `Decision::RECORD` may reach `LedgerService`.
- New prompt wording or schema change: bump `TransactionParser::VERSION`, extend `tests/Evals/cases.php`, and keep `php artisan moneytalks:ai:eval` green (no false or wrong records, ever).
- Model ids and prices are config/data (`config/ai.php`, `ai_model_prices`), never constants. Use the exact ids; do not append date suffixes.
- The user's text is untrusted: it only ever appears inside `<user_message>` after `PromptContext::sanitize()`. Send the model only what it needs (no history, no other users' data, no phone numbers).
- PHP SDK: `anthropic-ai/sdk` (`Anthropic\Client`); tests inject a fake PSR-18 transport (see `tests/Feature/AI/AnthropicProviderTest.php`), never the network.
- The `a + b` array operator keeps the LEFT value on key clashes; write `$overrides + $defaults`.

## Conversation rules of thumb (M6)
- At most ONE pending item per user (`ConversationStore`); it expires. Never send it, or any chat history, to the model.
- Anything that needs a Confirm tap is stored as a pending action and executed by `PendingActionService` with the key `confirm:{state id}`; never post from the handler on a guess.
- Follow-up answers are merged by `FollowUpParser` (no AI call). Accounts and people only ever match exactly.
- Undo/correction go through `LedgerService::reverse` / `correct`; there is no edit-in-place path.
- Work goes straight to `main` (owner's instruction); keep every commit green (`pest`, `pint`, `moneytalks:ai:eval`).

## Reports rules of thumb (M7)
- Every number in a report comes from `App\Services\Reporting\ReportService` (SQL over the ledger, scoped by `user_id`); formatting is
  deterministic (`ReportFormatter`). The model only picks a metric/period/filters; it never writes or rewrites a number.
- The model's period is a spec resolved by `PeriodResolver` (user timezone, Monday-Sunday weeks). Names resolve through `EntityResolver`
  in `QueryPlanner`; an unknown category/account is a question, never a silent "all".
- New metric: add it to `TransactionParser::QUERY_METRICS`, bump `VERSION`, plan it in `QueryPlanner`, format it in `ReportFormatter`,
  and test it against an independent oracle in `tests/Feature/Reporting`. Exports are CSV only; text cells go through `ExportService::safe()`.

## Debts rules of thumb (M8)
- Debts are ledger accounts per person (`AccountService::personAccount`) plus derived `debt_records`/`debt_settlements` kept by `DebtService` inside the
  ledger transaction. The verifier checks they agree; never write those tables elsewhere.
- Lend/borrow/split/card payments are never expenses or income. People match exactly; a new person is created by `CommandBuilder` at posting time only.
- Undo of a loan with repayments is refused (undo the repayments first); undoing a repayment re-opens the debt (settlements are voided, never deleted).

## Planning rules of thumb (M9)
- Budgets, goals, loans and recurring rules are *proposals confirmed by the user or applied only through the ledger*: nothing here writes `ledger_*` directly.
  A recurring rule never posts by itself (Paid tap or a matching payment the user logs); EMI payments always need a Confirm tap.
- Progress (budget used, goal saved, loan outstanding) is always derived from ledger balances/reports, never stored.
- Scheduled work (`moneytalks:recurring:run`, `moneytalks:monthly:close`) must be idempotent (keyed sends) and visible when it fails (outside the 24-hour window).
- `action` (set/remove) is shared by budgets, recurring rules and goals; amounts that create something long-lived must be provably in the message (`matches() === true`).
- Never chain a test run into `&&` through a pipe (`pest | tail && git commit` hides failures); check the exit code.

## Media rules of thumb (M10)
- Voice notes and photos are processed in memory and never stored; only extracted text goes to `ai_requests` (encrypted, purged). Never log media bytes or transcripts.
- Everything must pass `MediaGuard` first (MIME allow-list, size cap, per-user daily count); a vendor is only called after that.
- A transcript or a receipt read is only ever a *proposal*: every record from voice/photos needs a Confirm tap (`ProposedPosting::withSource`). The photo's confidence is capped.
- New STT vendor = new `SpeechToTextProvider`; nothing else may know its wire format. `STT_PROVIDER=fake` is refused in production.

## Operations rules of thumb (M11)
- Anything that costs money per use must check `AiSwitch::blocked()` first (`InterpretationService`, voice/photo handling already do).
- `/health` returns names and pass/fail only; details (counts, ages) are for the console command. Never put content or ids into a health check.
- Keep `moneytalks:health` honest: a new scheduled job gets a heartbeat or a "last ran" check, and a new failure mode gets a check.

## Hardening rules of thumb (M12)
- Anything stored that came from a user message (raw webhook payloads, bodies, AI bodies) is encrypted and has a retention purge; never persist exception *messages* (class names only).
- Erasure keeps the books: the only ledger column that may ever change is `description`, and only to NULL. Privacy export/erase and backups are console-only on purpose.
- A voice transcript or photo can never apply a change without a Confirm tap; creating a loan always needs one (it writes to the ledger).
- Never send the Meta bearer token to a URL outside Meta's domains; check media bytes against their declared type before any vendor sees them.
- Backups: encrypted, 0600, key off the server; a backup is only trusted after `backup:restore --into=` passes the ledger verifier.
