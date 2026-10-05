# MoneyTalks

A personal finance manager you talk to on **WhatsApp**. You message it ("spent 250 on vegetables", "Rahul returned 1000", "how much did I spend this month?");
an AI model (Claude Haiku) turns the message into a *proposal*; validated, deterministic code posts it to a **double-entry ledger**. The AI never writes to the
books and never computes a number: every figure you see comes from the ledger.

## What it does today

| You say | What happens |
|---|---|
| `spent 250 on vegetables`, `aaj 200 sabji`, `salary 45000`, `transfer 1000 from SBI to HDFC` | Recorded (transfers and big amounts ask you to tap Confirm). Hindi, Hinglish and English. |
| `undo`, `actually that was 600`, `that was yesterday` | Reverses or corrects the entry (nothing is ever edited in place). |
| `how much did I spend this month?`, `food this week`, `monthly report`, `compare this month with last month`, `biggest expense`, `export my transactions` | Exact answers from SQL; a CSV file for exports. |
| `gave Rahul 2000`, `Rahul returned 500`, `rahul se 5000 liya`, `who owes me?` | Lending, borrowing and repayments (oldest debt settled first). |
| `dinner 2400 split with Rahul and Amit`, `paid HDFC card 12000 from HDFC bank` | Split expenses and card bill payments (neither is an expense). |
| `set a budget of 5000 for food`, `how are my budgets?` | Monthly budgets with a heads-up at 80% and 100%. |
| `Netflix 649 every month`, `what are my subscriptions?` | Recurring payments: a reminder with Paid / Skip buttons when due. |
| `save 100000 for a bike by June`, `transfer 5000 to bike` | Savings goals with progress. |
| `bike loan 120000 at 10.5% for 24 months`, `paid bike EMI` | EMI loans with the interest and principal split. |
| a **voice note** or a **photo of a receipt** | Transcribed / read, then always shown to you with Confirm / Cancel. |
| (automatic) | A monthly wrap-up in the first week of each month. |

Progress and balances are always derived from the ledger. Anything that moves money between people or accounts, is large, or came from voice/photo needs your tap.

## Status

All milestones M0 to M12 are built; see [`docs/roadmap.md`](docs/roadmap.md) for the table and what is deliberately not built.
**Honest caveat: it has been tested with fakes and mocked HTTP, not yet against the real Meta and Anthropic services.** Follow the steps below in order,
and do not skip step 6 ("Prove it before trusting it").

## Stack
Laravel 13 · PHP 8.3+ · MySQL 8 / MariaDB (utf8mb4) · database queue + cron (no Redis, no daemons: Hostinger shared hosting works) · Anthropic Claude Haiku · Meta WhatsApp Cloud API.

---

# Setup (5 steps, one evening)

You need: Hostinger with **SSH + Composer + MySQL** and PHP 8.3/8.4, a Meta developer account, an Anthropic Console account.
Back up `APP_KEY` and `PII_BLIND_INDEX_KEY` off the server: losing either makes stored data unreadable.

## 1. Try locally (optional, free, no Meta/Anthropic needed)
```bash
cp .env.example .env && composer install && php artisan key:generate
# create a local MySQL database, set DB_* in .env (WHATSAPP_PROVIDER=fake and AI_PRIMARY_PROVIDER=fake for no network)
php artisan migrate
./vendor/bin/pest && php artisan moneytalks:ai:eval
```

## 2. Hostinger
In hPanel: create a **subdomain** with free SSL and its document root set to the app's **`public/`** folder; choose **PHP 8.3+** with
`pdo_mysql, mbstring, intl, bcmath, zip, curl, openssl, sodium`; create a **MySQL database + user**; enable **SSH**.

## 3. Code and `.env`
```bash
ssh -p <port> <user>@<host>
cd ~/domains/<yourdomain>/ && git clone <your repo> money && cd money     # or upload the folder
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate && nano .env
```
In `.env` set: `APP_URL`, `DB_*`, `ALLOWED_WA_IDS` (your number, digits with country code, no `+`), `ANTHROPIC_API_KEY`, and the `META_*` values from step 5.
Generate each secret with `php -r "echo bin2hex(random_bytes(32));"`: `PII_BLIND_INDEX_KEY`, `HEALTH_TOKEN`, `META_WEBHOOK_VERIFY_TOKEN`, and
`BACKUP_ENCRYPTION_KEY` (keep a copy off the server). Keep `APP_ENV=production`, `WHATSAPP_PROVIDER=meta`.
```bash
php artisan migrate --force && php artisan config:cache && php artisan route:cache
php artisan moneytalks:user:create <your number> --name="<you>"
```
`https://<sub>/up` must answer 200; `https://<sub>/.env` must not be reachable.

## 4. Cron (the "worker": no daemons)
hPanel -> Advanced -> Cron Jobs, two jobs, **every minute**:
```
* * * * * cd /home/<user>/domains/<yourdomain>/money && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/<user>/domains/<yourdomain>/money && php artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
```
(Use the full PHP path if the default is older than 8.3, e.g. `/opt/alt/php83/usr/bin/php`.) After two minutes `php artisan moneytalks:health` shows the scheduler OK.

## 5. Connect Meta WhatsApp (free test number is enough)
1. developers.facebook.com -> **Create App** (Business) -> add **WhatsApp**. In **API Setup** copy the **Phone number ID** (`META_PHONE_NUMBER_ID`) and
   **WhatsApp Business Account ID** (`META_WABA_ID`), and add + verify your own phone as a recipient.
2. App Settings -> Basic: **App ID** (`META_APP_ID`) and **App secret** (`META_APP_SECRET`).
3. Business Settings -> **System users** -> add an Admin, assign the app and WhatsApp account, **generate a token** with `whatsapp_business_messaging` and
   `whatsapp_business_management` -> `META_ACCESS_TOKEN` (the dashboard token expires in 24 hours).
4. WhatsApp -> Configuration -> Webhook: URL `https://<sub>/webhooks/whatsapp`, verify token = your `META_WEBHOOK_VERIFY_TOKEN`; **subscribe to `messages`**.
5. `php artisan config:cache`.

## Check it works
```bash
php artisan moneytalks:ai:eval --live        # ~10 US cents; must end "Eval gate passed", else stop and do not use real data
php artisan moneytalks:backup && php artisan moneytalks:backup:verify
```
Then WhatsApp the test number: `help`, `spent 250 on vegetables`, `balance`, `undo`, `how much did I spend this month?`.
It can only reply within 24 hours of your last message, so message it first. Nothing arrives? `php artisan moneytalks:health`, `storage/logs/laravel.log`, [runbooks](docs/runbooks.md).
Also set `AI_DAILY_BUDGET_GLOBAL_USD` (e.g. `1`) and point a free uptime monitor at `GET /health` with `Authorization: Bearer <HEALTH_TOKEN>`.

**Optional:** voice notes (`STT_PROVIDER=openai_compatible`, `STT_API_KEY`); opening balances
(`php artisan moneytalks:account:create <number> "HDFC Bank" bank --opening=52340.50 --alias=hdfc`); more in `docs/ai.md`.

## Everyday commands
| Command | Use |
|---|---|
| `moneytalks:health` | One table: is everything working? |
| `moneytalks:cost:report`, `moneytalks:ai:usage` | What the AI costs |
| `moneytalks:ai:pause "why"` / `moneytalks:ai:resume` | Kill switch for all AI spend |
| `moneytalks:ledger:verify` | Re-check the books (also runs nightly) |
| `moneytalks:balances <number>` | Account balances |
| `moneytalks:user:export <number>` / `moneytalks:user:erase <number>` | Your data (console only) |

Updating later: `git pull && composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan config:cache route:cache && php artisan moneytalks:health`.

## Documentation
Design and decisions live in [`docs/`](docs/README.md): [architecture](docs/architecture.md), [ledger](docs/ledger.md), [WhatsApp](docs/whatsapp.md), [AI](docs/ai.md),
[security](docs/security.md), [deployment](docs/deployment.md), [runbooks](docs/runbooks.md), [roadmap](docs/roadmap.md). Contributor rules are in [`CLAUDE.md`](CLAUDE.md).
