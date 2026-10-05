# Architecture

**Principle:** WhatsApp is the UI. The AI only *proposes*. Plain code *validates*, and the double-entry
**ledger** is the single source of truth. The AI never writes to the database and never computes a number.

## Stack
PHP 8.3+ / Laravel · MySQL or MariaDB · database queue + cron (no Redis, no daemons) · Claude Haiku (`AIProvider`) ·
Meta WhatsApp Cloud API (`WhatsAppProvider`) · Pest tests. Runs on Hostinger shared hosting. No admin UI: console commands only.

## The whole flow
```
You on WhatsApp
   |  message
   v
Webhook ........ check signature, save the message, answer 200 fast
   |
   v
Job (cron runs the queue every minute, or right after the 200)
   |
   v
Understand ..... AI turns the text into a JSON proposal -> code validates it -> a Decision:
   |             record | ask a question | ask for Confirm tap | refuse
   v
Do ............. Ledger posts it in ONE database transaction (or Reports read the ledger)
   |
   v
Reply .......... sent back through Meta (only inside WhatsApp's 24-hour window)
```

## Four layers, one direction
| Layer | Code | Job | Must not |
|---|---|---|---|
| 1. WhatsApp | `Services/WhatsApp` | Receive, send, media checks | Know about money |
| 2. Understand | `Services/AI`, `Services/Interpretation`, `Services/Conversation` | Proposal -> validated Decision; one pending question per user | Write to the ledger, trust the AI |
| 3. Do | `Domain/Ledger`, `Services/{Budgets,Goals,Loans,Recurring,Reporting}` | Post and read money | Know WhatsApp or the AI exist |
| 4. Ops | `Services/{Ops,Backup,Privacy,Closing}`, `Console/Commands`, `routes/console.php` | Health, backups, monthly jobs, erase/export | Touch the ledger except through `LedgerService` |

Vendors (Meta, Anthropic, speech-to-text) sit behind interfaces, so swapping one touches one class.

## Rules that make it safe
- **Idempotent:** a duplicate webhook or retry is a no-op (`wa_message_id`, ledger `idempotency_key`).
- **One transaction per financial write**; a crash cannot leave half an entry.
- **Append-only ledger:** undo and correction are new reversing entries, never edits.
- **Risky things need a Confirm tap:** transfers, large amounts, loans, anything from voice or photos.
- **AI failure is safe:** retry, then ask the user or fail politely; the message is never lost and never guessed.
- **Every user-owned table has `user_id`.** Money is integer minor units.

## Where to read more
Ledger rules `ledger.md` · tables `database.md` · WhatsApp `whatsapp.md` · AI prompts and evals `ai.md` · secrets and privacy `security.md` · hosting `deployment.md`.
