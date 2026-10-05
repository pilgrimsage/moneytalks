# Financial Ledger Rules

The ledger is a **double-entry, append-only, integer-minor-unit** system. The AI only
*proposes* an event; `PostingRules` (deterministic code) maps an event type to entries.

## 1. Account kinds

| Kind | Normal side | Increases with | Examples |
|---|---|---|---|
| `asset` | Debit | Debit | Cash, HDFC Bank, Wallet, Investments, **Receivable: Rahul**, Goal fund |
| `liability` | Credit | Credit | **HDFC Credit Card**, Bike Loan, **Payable: Amit** |
| `income` | Credit | Credit | system `Income` (category = Salary, Interest …) |
| `expense` | Debit | Debit | system `Expenses` (category = Vegetables, Fuel …) |
| `equity` | Credit | Credit | `Opening Balances`, `Reconciliation Adjustments` |

Subtypes (enum in code): `cash, bank, wallet, credit_card, loan, receivable, payable,
investment, goal, system`.

Per user, the system creates: `Expenses`, `Income`, `Opening Balances`,
`Reconciliation Adjustments`. Receivable/Payable accounts are created lazily **per
counterparty** (a person can have both; reports can show the net).

**Category is a dimension on the entry** (`category_id`), not an account (decisions B2).
**Payment method** (UPI, card, cash …) is metadata on the transaction; the *account*
on the entry says where the money actually came from. UPI is a channel: method=`upi`,
account=`HDFC Bank`.

## 2. Invariants (enforced in the DB, not only in code)

1. Per transaction, per currency: `SUM(debit) = SUM(credit)`. MySQL has no deferred constraint
   triggers, so this is enforced in layers: (a) the header row carries `debit_total_minor` and
   `credit_total_minor` with a `CHECK (debit_total_minor = credit_total_minor)`; (b) `LedgerService`
   re-reads `SUM(entries)` inside the same DB transaction and rolls back unless it equals the
   header totals (**verify-before-commit**); (c) a nightly job re-checks every transaction.
2. Entry amounts are `> 0`; direction is `D` or `C`.
3. `ledger_entries` rows are immutable: the Eloquent model refuses update/delete, there is no code
   path that issues them, and BEFORE UPDATE/DELETE triggers are installed **where the host grants
   TRIGGER privilege** (shared hosting often does not). Tamper evidence: each transaction stores
   `entries_hash` (hash of its entries), re-verified nightly.
4. `ledger_transactions` may only change `status`, `reversed_by_id`, `updated_at`.
5. All entries of a transaction share the transaction's `user_id`; entries may only
   reference accounts of that user (composite FK / check).
6. `idempotency_key` is unique.
7. A transaction is reversed at most once.

A nightly job recomputes `SUM(D) - SUM(C)` globally and per user and alerts on any
non-zero result.

## 3. Event → entries (the posting rules)

Notation: `Dr` debit, `Cr` credit. Amounts in ₹ for readability.

### 3.1 Expense (cash/bank/UPI)
"spent 250 on vegetables", cash

| | Account | Amount | Category |
|---|---|---|---|
| Dr | Expenses | 250 | Vegetables |
| Cr | Cash | 250 | |

### 3.2 Income
"salary 45000" into HDFC Bank

| | Account | Amount | Category |
|---|---|---|---|
| Dr | HDFC Bank | 45000 | |
| Cr | Income | 45000 | Salary |

### 3.3 Transfer between own accounts
"transfer 1000 from SBI to HDFC": **not** income or expense.

| | Account | Amount |
|---|---|---|
| Dr | HDFC Bank | 1000 |
| Cr | SBI Bank | 1000 |

### 3.4 Credit-card purchase
"bought shoes 3000 on HDFC credit card"

| | Account | Amount | Category |
|---|---|---|---|
| Dr | Expenses | 3000 | Shopping |
| Cr | HDFC Credit Card (liability) | 3000 | |

Card **outstanding** = balance of the liability account (credit minus debit).
**Available limit** = `credit_limit - outstanding`.

### 3.5 Credit-card bill payment
"paid HDFC card 12000": **not an expense** (the spend was recorded at purchase).

| | Account | Amount |
|---|---|---|
| Dr | HDFC Credit Card (liability) | 12000 |
| Cr | HDFC Bank | 12000 |

Settles the open statement (`credit_card_statements.paid_minor`). Paying more than the
outstanding leaves the card with a credit balance (allowed, shown as such).
Interest/late fees charged by the bank *are* expenses:
`Dr Expenses [Bank charges] / Cr Credit Card`.

### 3.6 Lending
"I gave Rahul 2000": **not an expense**.

| | Account | Amount |
|---|---|---|
| Dr | Receivable: Rahul (asset) | 2000 |
| Cr | Cash / bank | 2000 |

Creates a `debt_records` row (direction `receivable`, optional due date: "he'll return
it next week").

### 3.7 Repayment received
"Rahul returned 1000"

| | Account | Amount |
|---|---|---|
| Dr | Cash / bank | 1000 |
| Cr | Receivable: Rahul | 1000 |

Allocated to open `debt_records` oldest-first (FIFO), recorded in `debt_settlements`.
If amount > open receivable → ask (overpayment: gift? new borrowing?). If **no** open
receivable exists → ask, never assume (decisions C5).

### 3.8 Borrowing
"I took 5000 from Amit": **not income**.

| | Account | Amount |
|---|---|---|
| Dr | Cash / bank | 5000 |
| Cr | Payable: Amit (liability) | 5000 |

### 3.9 Repaying a borrowed amount
"I paid Amit 2000"

| | Account | Amount |
|---|---|---|
| Dr | Payable: Amit | 2000 |
| Cr | Cash / bank | 2000 |

### 3.10 Split expense, user paid everything
"Dinner 2400 split between me, Rahul and Amit" (3-way equal)

| | Account | Amount | Note |
|---|---|---|---|
| Dr | Expenses | 800 | my share, category Restaurant |
| Dr | Receivable: Rahul | 800 | |
| Dr | Receivable: Amit | 800 | |
| Cr | Cash / bank | 2400 | what I paid |

Rounding: shares are computed in minor units; any remainder (e.g. ₹100.00 / 3) is
allocated one minor unit at a time starting with the payer, deterministically, so
shares always sum to the total.

Unequal: "Dinner 3000, I paid 1000, Rahul owes 1200, Amit owes 800", where the sum of
shares (my 1000 + 1200 + 800) must equal 3000, otherwise → clarify.
If **someone else paid** (friend paid 2400, my share 800): `Dr Expenses 800 / Cr Payable: friend 800`.

### 3.11 Refund
"Amazon refunded 1200" to the original method.

| | Account | Amount | Category |
|---|---|---|---|
| Dr | HDFC Bank (or the card liability, if refunded to card) | 1200 | |
| Cr | Expenses | 1200 | original category (negative expense) |

Linked to the original via `related_transaction_id` when identifiable.
Cashback/interest are **income**, not refunds.

### 3.12 Loan / EMI
Loan creation (disbursement, optional): `Dr Bank / Cr Loan liability`. Tracking an
existing loan only: an opening balance entry (`Dr Opening Balances / Cr Loan liability`).

EMI payment "paid 4200 bike EMI" from HDFC:

| | Account | Amount |
|---|---|---|
| Dr | Bike Loan (liability) | principal part |
| Dr | Expenses [Loan interest] | interest part |
| Cr | HDFC Bank | 4200 |

The split comes from the `loan_installments` schedule (reducing-balance amortisation:
`interest = outstanding × annual_rate / 12`). Lenders' actual schedules can differ, so the
user may override ("interest was 1100"); the override is audited. If no rate is known,
the whole EMI is treated as principal and flagged "interest unknown".

### 3.13 Recurring payment
A rule creates **expected** occurrences only. Nothing is posted until the payment is
confirmed (button "Paid" on the reminder, or the user logs it and the matcher links it).
On confirmation it posts as an ordinary expense/transfer/EMI (rules above) with
`recurring_occurrence_id` set. Statuses: `expected → due → paid | skipped | cancelled`.

### 3.14 Opening balance and reconciliation adjustment
"My HDFC balance is 52,340" at setup: `Dr HDFC / Cr Opening Balances`.
In reconciliation: expected balance vs. stated; a difference is posted only after user
confirmation as `Dr/Cr Reconciliation Adjustments` against the account. Never silently.

### 3.15 Gifts and ambiguous "to person" payments
"paid Rahul 500" with no context → ask: *lent / repayment of a debt / expense (e.g.
gift, share of bill)?* Gift received → income (category Gift).

## 4. Reversal, correction, void

- **Undo/Delete** = post a *reversing* transaction (each entry with opposite direction),
  `reversal_of_id = original`; original `status = reversed`. History is never removed.
- **Correction** ("actually 600 not 500", "that was HDFC card"): `void` the original
  via reversal and **repost** a corrected transaction with `corrects_id = original`.
  This applies to **every** change, including category, merchant or description: entries and
  headers are immutable, so there is no "edit in place" path (simpler and impossible to get wrong;
  decided while implementing M3, replacing the earlier "metadata edit with audit record" idea).
  A correction is atomic and idempotent: retrying the same one is a replay, and correcting
  something already reversed or corrected is refused. Settled/allocated debt records are
  re-derived from the reposted transaction (M8).
- A reversal is dated like the original (so the mistaken entry disappears from the period it
  polluted), has `type = reversal`, and cannot itself be reversed (post a new transaction instead).
- All three write `audit_logs` (actor: user via WhatsApp / admin / system, reason,
  `wa_message_id`, before/after JSON).
- Statuses: `posted`, `reversed`, `voided` (pre-posting cancel; no entries), plus
  `pending_confirmation` held outside the ledger in `conversation_states`, never as
  a half-posted ledger row.

## 5. Net worth and reports

```
Assets      = Σ balance(asset accounts)        (cash, bank, wallet, investments, receivables, goal funds)
Liabilities = Σ balance(liability accounts)    (cards, loans, payables)
Net worth   = Assets − Liabilities
```
Income/expense accounts are excluded from net worth; they are *flows* that already
moved asset/liability balances, which avoids double counting. Savings = `income −
expenses` for a period (transfers, lending, borrowing, card payments are excluded
by construction).

## 6. Multi-currency (schema-ready, INR-only behaviour at MVP)

- Entries carry `amount_minor + currency`; the transaction carries
  `base_amount_minor, base_currency, exchange_rate` for reporting.
- Each transaction balances **per currency**. Cross-currency conversion posts to a
  system `FX` account so each currency's side still balances.
- Rates come from `exchange_rates` (source + date). Rounding: half-even, explicit.
  Not enabled until Phase 8.

## 7. Concurrency and idempotency

- One DB transaction per command: header + entries + audit (+ debt records) → commit or
  roll back as a unit.
- `idempotency_key = {wa_message_id}:{item_index}` (unique) → retried jobs are no-ops.
- Every write first takes `SELECT … FOR UPDATE` on the user's row, which serialises that user's writes
  (also used for the per-message mutex in M4). Reversal additionally locks the original transaction.
  The unique idempotency key is a second, independent backstop. `tests/Concurrency` proves this
  with real parallel PHP processes (same webhook x8, 10 parallel expenses, racing undo, racing
  corrections); removing the lock makes the correction race fail with deadlocks, so the test has teeth.
- Duplicate *content* detection (user sends "paid 500 groceries" twice) is separate from
  idempotency: same user + amount + category/merchant + account within a configurable
  window → ask "Record it anyway? Yes/No".

## 8. Property tests (to be written with the ledger)

- Random event sequences keep `ΣD = ΣC` globally and per transaction.
- Apply then reverse any event → all balances return to their prior values.
- Split allocation always sums to the total, for any amount and participant count.
- Replaying all entries from scratch reproduces every derived balance and report.
