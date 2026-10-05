# Roadmap

## Status

| Milestone | What | State |
|---|---|---|
| M0-M2 | Design docs, scaffold, identity and reference data | done |
| M3 | Double-entry ledger (append-only, verified, idempotent) | done |
| M4 | WhatsApp Cloud API (signed webhook, idempotent, 24h window) | done |
| M5-M6 | AI interpretation, follow-ups, Confirm taps, duplicates, undo, corrections | done |
| M7 | Questions, reports and CSV export | done |
| M8 | Debts (lend/borrow/repay), split expenses, credit-card bill payments | done (card statements/limits deferred) |
| M9 | Budgets + alerts, recurring payments with reminders, goals, EMI loans, monthly summary | done |
| M10 | Voice notes (pluggable STT) and receipt photos (Claude vision) | done (voice needs your STT key) |
| M11 | Health checks, cost report, AI kill switch and budget cap | done (console + `/health`, no admin UI) |
| Telegram | Telegram Bot API as a second chat channel (`TelegramProvider`, webhook command) | done; WhatsApp parked (Meta business account locked) |
| M12 | Security review, encrypted backups + restore drill, privacy export/erase, retention, runbooks | done for personal mode |

**Everything is built and tested against fakes and mocked HTTP only.** It has not yet been run against the real Meta and Anthropic services; the first live session is the
remaining risk. Follow "Check it works" in the root `README.md` (including `moneytalks:ai:eval --live`) before trusting it with real data.

## Personal mode (current target)
One user (the owner). The 24-hour window is not a concern while you message the bot daily. The schema keeps `user_id` everywhere so more users remain possible.

| Not built | Instead |
|---|---|
| Plans, billing, quotas, invites | `ALLOWED_WA_IDS` allow-list; other senders get no reply and no AI |
| Consent and onboarding flow | Console `moneytalks:user:create`; required before any other user |
| Admin UI, RBAC, MFA | Console commands and a token-protected `GET /health` |
| Notification template registry | Out-of-window reminders are recorded as `outside_window_no_template`, never dropped |
| PIN step-up | Not needed: no chat path to destructive actions exists |

## Deliberately deferred
Credit-card statements, limits and due dates · "someone else paid" splits · Excel/PDF export (CSV only) · statement import and reconciliation · multi-currency (schema ready, INR only) ·
investments and forecasting · Sonnet escalation and rule-based fast-path (exist behind flags or not at all) · STT vendor bake-off and transcription cost pricing · load tests beyond the concurrency suite.

## Risks to watch
1. **Hinglish accuracy on Haiku:** the eval suite (`tests/Evals/cases.php`) judges every prompt change; grow it from real messages.
2. **AI cost:** tracked per request from M5; the daily budget and kill switch bound it.
3. **Notification cost:** template messages outside the window cost money and need Meta approval.
4. **Legal** (DPDP, Meta policy, retention vs. erasure): review before letting anyone else use it.
