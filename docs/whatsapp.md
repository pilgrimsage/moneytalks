# Chat channels: Telegram and WhatsApp

`WHATSAPP_PROVIDER` picks the channel (the name is historical): `telegram` (recommended), `meta` (WhatsApp Cloud API) or `fake` (local/tests). Both channels' settings can stay in `.env` together; switching is that one line plus the matching allow-list (`ALLOWED_TELEGRAM_IDS` or `ALLOWED_WA_IDS`). A Telegram id and a WhatsApp number are different users with separate books. Everything below the provider
interface (users, allow-list, processing, replies, media rules) is identical for both.

## Telegram
`TelegramProvider` is the only class that knows the Bot API. A private chat id equals the Telegram user id (a plain number), so it uses the same "id" slot, `ALLOWED_WA_IDS` allow-list and blind index as a WhatsApp number.
- **Webhook:** `POST /webhooks/telegram`, authenticated by the secret you registered with `setWebhook`, which Telegram returns in `X-Telegram-Bot-Api-Secret-Token` (constant-time compare, fail closed without `TELEGRAM_WEBHOOK_SECRET`). Manage it with `php artisan moneytalks:telegram:webhook info|set|delete`.
- **Served:** text, voice notes, photos, button taps (inline keyboard; callback data = the button id, and every tap is answered so the spinner stops). Groups, channels and other bots are ignored. `/start` and `/help` mean `help`; `/balance` means `balance`.
- **No window, no templates:** Telegram has no 24-hour limit, so `enforcesServiceWindow()` is false and reminders always send. Templates are refused. No delivery receipts, so no statuses.
- **Formatting:** replies use `*bold*` and `_italic_`; they are converted to Telegram HTML with everything else escaped.
- **Media:** `getFile`, then a download from `api_base` only (the token is in the URL, so it never goes elsewhere); the type comes from the file extension and `MediaGuard` still checks the bytes.
- **Secrets:** the bot token is part of every API URL. Errors and logs carry Telegram's description and code only, never a URL or the HTTP client's message.
- Setup steps are in the root `README.md`. Env: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `ALLOWED_TELEGRAM_IDS` (falls back to `ALLOWED_WA_IDS` when empty).

# WhatsApp (Meta Cloud API, direct)

Meta's numeric limits (button caps, file sizes, error codes) change; re-verify against Meta's current docs before go-live: Graph API version (`META_GRAPH_VERSION`),
retryable error codes (130429, 131056, 80007), the out-of-window code (131047), and the webhook field names.

## 1. Provider abstraction
`WhatsAppProvider` is the interface; `MetaWhatsAppProvider` is the only class that knows Graph API URLs, field names and versions (a new vendor = a new implementation).
`FakeWhatsAppProvider` has the same parsing and signature check with no network (refused in production). The rest of the app sees only the DTOs
(`InboundMessage`, `InboundStatus`, `Outbound`, `MediaFile`).
Supported: send text, reply buttons, templates, documents (media upload then `type: document`, used for CSV exports); parse text, audio, image, document, interactive/button replies and delivery statuses with pricing;
`downloadMedia()` (two-step Graph fetch, size checked first, bearer token sent only to Meta's https URLs); `markRead` (best-effort).
Not built: list messages, notification template registry, statement documents.

## 2. Inbound
```
POST /webhooks/whatsapp -> verify signature -> save webhook_events -> answer 200 -> ProcessWebhookEvent job
   -> per message: skip if wa_message_id seen -> allow-list check -> route by type
```
- **GET** handshake: `hub.verify_token` (constant-time compare) -> echo `hub.challenge`.
- **POST**: check `X-Hub-Signature-256` = `sha256=HMAC(app_secret, raw body)` on the raw bytes before JSON decoding; reject a foreign `phone_number_id`.
- The controller only authenticates, persists, enqueues and answers 200. No AI, no ledger, no outbound calls. It is throttled per IP; no session or CSRF.
- Processing is idempotent by `wa_message_id`, runs under a per-user lock, and a rate limit applies (`WHATSAPP_USER_MSGS_PER_MIN`).
- **Strangers:** a sender who is not provisioned, not in `ALLOWED_WA_IDS`, or suspended gets no reply and no AI, and their message content is **not stored**. An empty allow-list ignores everyone.
- **Failures are kept:** a handler error leaves the text stored (`processing_failed`) and the event `failed`. The scheduled reaper retries with linear backoff up to `WHATSAPP_MAX_ATTEMPTS`, then logs `whatsapp.events_exhausted` at critical level.
- Routing: text -> interpretation; audio -> `MediaGuard` -> speech-to-text -> interpretation; image -> `MediaGuard` -> receipt reader -> Confirm; button replies -> a deterministic router (no AI) that maps the button id (`{action}:{pending_id}`) to a pending action. Expired ids get "that request expired, please send it again".

## 3. Outbound
Everything goes through `OutboundMessageService`:
1. Persist the row (`direction out`) with a `dedupe_key` so retries are safe.
2. **24-hour window:** free-form text only while `users.last_inbound_at` is within the window; otherwise only a template. With no template mapping the message is *not sent* and recorded as `failed: outside_window_no_template`, never silently dropped (and not auto-resent).
3. Send with retry/backoff on 429/5xx. Replies use keys `reply:{wa_message_id}:{n}`.
4. Store Meta's message id; status webhooks (`sent`, `delivered`, `read`, `failed`) update the row and carry pricing info.
Failures show up in `php artisan moneytalks:health` and `moneytalks:whatsapp:messages`.

## 4. UX
| Need | Mechanism |
|---|---|
| Confirmation, answers | Plain text (`*bold*`, `_italic_`) |
| Confirm / Cancel, Paid / Skip, Yes / No | Reply buttons (at most 3) |
| Reports | Short text sections, no wide tables |
| Exports | CSV generated in code, sent as a document (inside the window) |
| `help`, `balance`, `undo`, yes/no | Recognised without AI |

## 5. Media
Voice notes and photos are processed **in memory and never stored**; only extracted text goes to `ai_requests` (encrypted, purged). `MediaGuard` runs first: MIME allow-list, size caps (`WHATSAPP_MAX_AUDIO_BYTES`, `WHATSAPP_MAX_IMAGE_BYTES`),
per-user daily count (`WHATSAPP_MEDIA_PER_USER_DAILY`), and a check of the bytes against their declared type. Every record from voice or a photo needs a Confirm tap (`ai.md`).

## 6. Rate limits and abuse
Per-user messages/minute and media/day; over the limit gets one polite message, then silence for the cool-down. Global: the AI kill switch (`moneytalks:ai:pause`) and the daily budget. Unknown numbers: no reply, no AI.

## 7. Onboarding
Personal mode has no consent flow. You are created from the console (`moneytalks:user:create <number>`), which seeds starter categories and defaults (Asia/Kolkata, INR), and your number must be in `ALLOWED_WA_IDS`.
A consent/onboarding flow is needed before any other user (`roadmap.md`).

## 8. Environment
`WHATSAPP_PROVIDER` (`meta`, or `fake` locally), `META_GRAPH_VERSION`, `META_APP_ID`, `META_APP_SECRET`, `META_WABA_ID`, `META_PHONE_NUMBER_ID`, `META_ACCESS_TOKEN` (permanent System User token, never the 24-hour one),
`META_WEBHOOK_VERIFY_TOKEN`, `ALLOWED_WA_IDS`, `WHATSAPP_*` limits (see `config/whatsapp.php`). Setup steps are in the root `README.md`.

## 9. Local development
`WHATSAPP_PROVIDER=fake`, `php artisan serve`, then `php artisan moneytalks:whatsapp:simulate "spent 250 on vegetables"`; inspect with `php artisan moneytalks:whatsapp:messages`.
The simulator supports `--id` (resend the same message id), `--bad-signature`, `--kind=audio|image|button|status`, `--from` and `--handshake`.

## 10. Before relying on a real number
The free Meta test number needs no business verification and is enough for personal use. A dedicated number needs business verification, an approved display name, and approved templates for any reminders you want outside the 24-hour window.
