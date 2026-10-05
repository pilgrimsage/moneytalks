# MoneyTalks: WhatsApp AI Personal Finance Manager

**Status: milestones M0 to M12 are built (see [roadmap.md](roadmap.md)). Hosting: Hostinger + MySQL, no Docker, no CI/CD.**

Stack: Laravel · MySQL/MariaDB (Hostinger) · database queue + cron · Claude Haiku (via `AIProvider`) · Meta WhatsApp
Cloud API (direct, via `WhatsAppProvider`) · designed for a future multi-user SaaS.

## Read in this order

| # | Document | What it settles |
|---|---|---|
| 1 | [decisions.md](decisions.md) | **Start here.** Spec problems found, my proposals, and the 🔴 decisions needing your sign-off |
| 2 | [architecture.md](architecture.md) | One-page overview: flow, four layers, safety rules |
| 3 | [ledger.md](ledger.md) | Double-entry rules for every financial event, invariants |
| 4 | [database.md](database.md) | ER diagrams, tables, indexes |
| 5 | [whatsapp.md](whatsapp.md) | Webhook → queue → processor → outbound, 24h window, onboarding |
| 6 | [ai.md](ai.md) | Pipeline, routing, JSON schemas, validation, injection defense, eval suite |
| 7 | [security.md](security.md) | Threat model, secrets, encryption, retention, privacy |
| 8 | [cost-model.md](cost-model.md) | AI/WhatsApp/infra cost tracking and unit economics |
| 9 | [api.md](api.md) | `/api/v1` and admin endpoints |
| 10 | [testing.md](testing.md) | Test strategy and evals |
| 11 | [deployment.md](deployment.md) | Hostinger setup, cron, env vars |
| 12 | [roadmap.md](roadmap.md) | MVP scope, what is *not* built first, milestones |

## Facts to verify before implementation
Meta limits/pricing/template rules, Anthropic model IDs and prompt-cache minimums, and
DPDP/retention obligations are stated as design assumptions here and must be checked
against current official documentation and legal advice.

- [runbooks.md](runbooks.md): what to do when something breaks, backups and restore drills, lost or leaked secrets.
