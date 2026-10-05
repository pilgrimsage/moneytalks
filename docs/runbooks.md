# Runbooks (personal deployment on Hostinger)

Each runbook is short on purpose: what you see, what to run, how to know it worked. Commands run from the project directory over SSH.

## 0. First thing to check, always
`php artisan moneytalks:health` shows scheduler, queue, webhooks, ledger, AI, backups and outbound messages in one table.
Anything marked FAIL is critical; WARN needs attention soon.

## 1. WhatsApp messages get no reply
1. `moneytalks:health`: is the **scheduler** OK? (FAIL = the cron job is not running: fix the hPanel cron entry, see deployment.md section 3.)
2. `php artisan moneytalks:whatsapp:messages`: is your message there? If not, Meta is not reaching you: check the callback URL and that the webhook is subscribed to `messages`.
3. Look at `webhook_events` (status/attempts). `failed` events are retried by the reaper every minute; after the maximum attempts you get one apology message.
4. Outside the 24-hour window the bot cannot reply (`outside_window_no_template`): message it first.
5. `moneytalks:ai:usage` / health "AI": is AI paused or over budget? Resume with `moneytalks:ai:resume`.

## 2. AI cost spike or a bad model day
- `php artisan moneytalks:ai:pause "reason"` stops every model, vision and transcription call immediately. Balances, reports and undo still work. `moneytalks:ai:resume` switches it back on.
- `moneytalks:cost:report --days=7` shows cost by feature and per transaction. Set `AI_DAILY_BUDGET_GLOBAL_USD` so it closes by itself.
- A leaked Anthropic key: rotate it in the Anthropic console, update `.env`, run `php artisan config:clear` (and `config:cache` again).

## 3. Ledger verification FAILS
Never "fix" ledger rows by hand. `php artisan moneytalks:ledger:verify` prints what is wrong (header/entry mismatch, hash mismatch, debt records vs balances).
1. Stop writing: `moneytalks:ai:pause "ledger check failing"`.
2. Restore the latest good backup into a scratch database (runbook 5) and run the verifier there. If the backup verifies, the live data was altered after it: compare, and restore.
3. If the problem is only a debt record vs person-balance drift, say so in the report and ask for a developer fix; do not edit the debt tables.

## 4. Backups
- Once: `php artisan moneytalks:backup:key`, put the value in `BACKUP_ENCRYPTION_KEY` and **store a copy off the server** (password manager). Without it a backup cannot be read.
- Daily 03:40 `moneytalks:backup` writes `storage/app/private/backups/moneytalks-*.sql.gz.enc` (mode 0600, newest `BACKUP_KEEP` kept).
- Copy the newest file off the server regularly (hPanel backups are not enough on their own). `moneytalks:health` warns when the last backup is older than 36 hours.
- `php artisan moneytalks:backup:verify` decrypts and checks the newest file is a complete dump.

## 5. Restore drill (do it quarterly, and before trusting a new host)
1. In hPanel create an **empty** scratch database and give your DB user access to it.
2. `php artisan moneytalks:backup:restore --into=<scratch db>`
3. Success prints "the ledger verifies. This backup is proven." Then drop the scratch database.
4. For a real disaster restore: restore into a new empty database the same way, point `.env` at it (`DB_DATABASE`), `php artisan migrate --force`, `php artisan moneytalks:ledger:verify`, then re-enable.

## 6. Lost or leaked secrets
| Secret | If lost | If leaked |
|---|---|---|
| `APP_KEY` | encrypted fields (message bodies, AI bodies, phone numbers) become unreadable: restore from your off-server copy | rotate: needs a re-encryption job (not automated yet); treat message bodies as exposed |
| `PII_BLIND_INDEX_KEY` | numbers can no longer be looked up: restore the copy | rotate with a re-index (not automated yet) |
| `META_ACCESS_TOKEN` | create a new System User token | revoke it in Meta Business settings now, create a new one, update `.env` |
| `META_APP_SECRET` | Meta dashboard | reset it in the Meta app dashboard (webhooks fail signature checks until `.env` matches) |
| `ANTHROPIC_API_KEY`, `STT_API_KEY` | create new | revoke in the vendor console, update `.env` |
| `BACKUP_ENCRYPTION_KEY` | **backups are unreadable**: make a new key and a fresh backup immediately | create a new key; older backups stay readable only with the old one |
| `HEALTH_TOKEN` | pick a new one | change it; the endpoint only shows pass/fail |

## 7. Your data: export and erase
- `php artisan moneytalks:user:export <number>`: JSON of everything stored (file mode 0600; delete it when done).
- `php artisan moneytalks:user:erase <number>`: removes messages, names, the number and ledger descriptions; the books keep anonymous amounts. Export first. It cannot be undone.
- Neither is reachable from WhatsApp on purpose.

## 8. A new release
`git pull`, `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan config:cache route:cache`, then `php artisan moneytalks:health`. Rollback: previous git tag
(migrations are written to keep the previous release working).
