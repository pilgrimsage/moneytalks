# AI

**AI proposes; code disposes.** The model turns one message into a typed *proposal*. It has no tools, no database access and never sees record ids.
The schema and wording live in code (`app/Services/AI/Prompts/TransactionParser.php`, `ReceiptParser.php`), which is the authority; this doc explains the behaviour.

## 1. Pipeline
```
text / transcript
  -> deterministic shortcuts (help, balance, undo, yes/no, buttons): no AI
  -> AIGateway (Haiku; optional stronger model, off by default) -> structured JSON via output_config.format
  -> ProposalValidator -> Decision: RECORD | ASK | CONFIRM | QUERY | EXPORT | DECLINE
  -> only RECORD (or an applied Confirm) reaches LedgerService; QUERY goes to Reporting
```
- `AIProvider` interface: `AnthropicProvider` (official PHP SDK) and `FakeAIProvider` (scripted; refused in production). Nothing else knows the vendor.
- `AIGateway` does routing, one retry on transient errors, and writes an `ai_requests` row for every attempt (tokens, latency, cost from `ai_model_prices`).
  Bodies are encrypted and purged after `AI_RETENTION_DAYS`. Exhausted retries give the user a polite "try again"; the message is kept.
- Model ids and prices are config/data (`config/ai.php`, `ai_model_prices`), never constants. Use exact ids.
- Structured outputs (not forced tool use): schemas are closed, every property required (nullable if optional), no min/max keywords. One flat item per intent.

## 2. Before the model: deterministic prep
- `AmountNormalizer` reads `2k`, `2 lakh`, `₹1,20,000`, Devanagari digits. The model's amount **must appear in the message**.
- `AliasHinter` includes only the user's matching aliases/accounts/people (e.g. `sabji -> Vegetables`) in the prompt, nothing else.
- The user's text is untrusted: it appears only inside `<user_message>` after `PromptContext::sanitize()`. No history, no other users' data, no phone numbers.
- The model returns a **date spec** (`today`, `relative_days`, `weekday`, ...); `DateResolver` does the calendar maths in the user's timezone.

## 3. Intents
`record_event` (event types: expense, income, transfer, credit_card_payment, lend, borrow, repayment_received/made, split_expense, refund, emi_payment, opening_balance),
`undo_transaction`, `correct_transaction`, `query`, `report`, `export`, `create_recurring`, `create_budget`, `create_goal`, `create_loan`, `update_setting`, `help`, `unknown`.
Current prompt: `TransactionParser::VERSION` (bump it on any wording/schema change, extend `tests/Evals/cases.php`, keep the eval green).

What each does:
| Area | Behaviour |
|---|---|
| **Expense / income / transfer** | Recorded if the score is high; transfers and amounts above `AI_CONFIRM_ABOVE_MINOR` always need a Confirm tap; a middling score also asks for Confirm; a low score asks the user to rephrase. |
| **Questions, reports, exports** | The model only fills `query_metric`, `period`, `compare_period`, `group_by`, `limit`, `search_text` and filters. `QueryPlanner` -> `ReportService` (SQL) -> `ReportFormatter`. No model call after the proposal, so no number is invented. Exports are CSV only. |
| **Debts, splits, cards** | `lend`, `borrow`, split and card payments always need Confirm. People match **exactly** (alias or full name); a near match is a question; a new name becomes a person only after Confirm. Repayments are checked against what is owed. Odd paisa in a split goes to the payer. |
| **Budgets** | `create_budget` applied by `BudgetService`; the amount must be provably in the message and the category must match exactly. Heads-up at 80% and 100% (once per budget/month/threshold, `budget_alerts`). |
| **Recurring** | `create_recurring` makes a rule that never posts by itself. Hourly `moneytalks:recurring:run` sends a Paid/Skip reminder (one nag after two days). Paid posts an ordinary expense. Outside the 24-hour window the reminder is recorded as failed, never silently dropped. |
| **Goals** | A goal is an asset account; saving is an ordinary confirmed transfer; progress is the balance, never stored. |
| **Loans / EMI** | `create_loan` opens a liability account. `emi_payment` always needs Confirm and posts principal + interest (integer maths; an unstated EMI is an estimate and the reply says so). |
| **Monthly wrap-up** | `moneytalks:monthly:close` sends last month's summary once per user in the first week of the month; failures retry. |
| **Voice notes** | `MediaGuard` -> download into memory -> `SpeechToTextProvider` (`STT_PROVIDER`: `none` declines politely, `openai_compatible`, `fake`) -> handled as text after "I heard: ...". **Every voice record needs Confirm.** Audio is never stored. |
| **Receipt photos** | One vision call (`receipt_parser`) returns merchant, total, date, category. Validated like a typed expense, confidence capped at 0.85, **always Confirm**. The image is never stored. |

Deliberately not done: Excel/PDF export, "someone else paid" splits, card statements/limits, multi-currency, investments. Replies are English; clarification questions are fixed templates (the model's own question is not echoed).

## 4. Conversation layer
- At most **one pending item per user** (`conversation_states`, encrypted, 10-minute TTL, purged hourly): a question, or something awaiting Confirm/Cancel. Never sent to the model.
- **Follow-ups without AI:** `FollowUpParser` merges "groceries using UPI" / "hdfc" / "250" into the earlier item and re-validates. Anything that isn't clearly an answer (new amount, command, chit-chat) is a new message and drops the pending question. At most three follow-ups.
- **Confirm taps:** executed by `PendingActionService` with key `confirm:{state id}`: idempotent, owner-only, expires with the state. An unrelated message cancels a pending confirmation.
- **Duplicates:** same type/amount/date/category within `DUPLICATE_WINDOW_SECONDS` asks "Record it anyway?". A redelivered webhook is never a duplicate.
- **Undo:** `undo` right after recording is instant and names what it undid; older or specific targets ask first. **Corrections** ("actually that was 600", "that was yesterday") show Was/Now with Apply/Cancel then `LedgerService::correct`. No edit-in-place.

## 5. Validation (`ProposalValidator`, in order)
Closed enums and schema -> amount positive, within `AI_MAX_AMOUNT_MINOR`, present in the text -> date resolved and not absurd -> entities (accounts and people exact only; categories/merchants may be fuzzy; unknown = a question, never silent "all") -> accounting compatibility (card payment needs a card, split shares sum, repayment needs a person) -> duplicate check -> **score** -> decision.

The model's self-reported confidence is only one input. Rough shape: `score = confidence + entity resolution + amount-in-text − intent risk − anomaly`; `>= AI_AUTO_COMMIT_MIN_SCORE` records, `>= AI_CONFIRM_MIN_SCORE` asks Confirm, below asks to rephrase. Always-confirm: transfers, debts, loans, large amounts, anything from voice or photo.

## 6. Cost and safety
- Anything that costs money per use checks `AiSwitch::blocked()` first. Budget and kill switch: `cost-model.md`.
- Compact prompts, only matching aliases, no history, small `max_tokens`. The system prompt is marked cacheable but is probably below the minimum size, so don't rely on caching.
- A retried message whose follow-up was already applied is read as new (one extra AI call at worst); ledger idempotency keys prevent a double posting.

## 7. Evaluation
`php artisan moneytalks:ai:eval` replays `tests/Evals/cases.php` (input, context, expected validated outcome; Hinglish, typos, ambiguity, injection, timezone edges). Metric that matters: **no false or wrong records, ever**. Any prompt/model/schema change must keep it green.
The replay tests our handling, not the model. **Real Haiku accuracy is not yet measured:** run `php artisan moneytalks:ai:eval --live` once with a real key (about 10 US cents) before trusting it.
Cost numbers are estimates from `ai_model_prices`; check them against the invoice.

## 8. Environment
`AI_PRIMARY_PROVIDER`, `ANTHROPIC_API_KEY`, `AI_MODEL_FAST` (default `claude-haiku-4-5`), `AI_MODEL_STRONG` + `AI_ESCALATION_ENABLED` (optional), `AI_REQUEST_TIMEOUT_MS`, `AI_RETENTION_DAYS`,
`AI_CONFIRM_ABOVE_MINOR`, `AI_CONFIRM_MIN_SCORE`, `AI_AUTO_COMMIT_MIN_SCORE`, `AI_MAX_AMOUNT_MINOR`, `AI_DAILY_BUDGET_GLOBAL_USD`, `AI_USER_REQUESTS_PER_DAY`, `STT_*`. All are read in `config/ai.php` and `config/stt.php`.
