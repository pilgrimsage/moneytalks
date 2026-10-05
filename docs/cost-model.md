# Cost

Goal: know what the AI costs, without hard-coded prices. WhatsApp and hosting costs are small and tracked by hand.

## AI cost
- Every model call (success or failure) writes an `ai_requests` row: provider, model, request type, prompt version, input/output/cached tokens, latency, status, estimated cost, `user_id`.
- Prices live in `ai_model_prices` (per-million-token rates with `effective_from`); `CostCalculator` multiplies the provider's returned usage by the rate in force at request time. Changing a price = inserting a row.
- Reports: `php artisan moneytalks:ai:usage` (by day/model/outcome, cost per recorded transaction) and `moneytalks:cost:report`.

## Controls
- `AI_DAILY_BUDGET_GLOBAL_USD`: when spent, AI is blocked (`AiSwitch`) and the bot says so. `AI_USER_REQUESTS_PER_DAY` caps a user.
- `moneytalks:ai:pause "why"` / `moneytalks:ai:resume`: manual kill switch. Anything that costs money per use checks `AiSwitch::blocked()` first.

## WhatsApp
Meta charges per conversation/message category, and rates change; check Meta's current pricing. Replies inside the 24-hour window you opened are the cheap case, so keep notifications few and opt-in.

## Where the savings are
Deterministic reports (zero tokens) · alias hints instead of big prompts · Haiku by default · short prompts and caching · commands and buttons that skip the AI. The eval run (`moneytalks:ai:eval`) reports cost per case, so a prompt change shows its cost before you ship it.
