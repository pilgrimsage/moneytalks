<?php

namespace App\Services\WhatsApp;

use App\Domain\Ledger\AccountService;
use App\Enums\AccountKind;
use App\Enums\TransactionType;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Interpretation\ProposedPosting;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** Turns results into short WhatsApp messages. Numbers come from the ledger/validator, never from the model. */
class ReplyFormatter
{
    public function recorded(ProposedPosting $p): string
    {
        $amount = $p->money->format();

        $first = match ($p->type) {
            TransactionType::Expense => "✅ Recorded {$amount} expense under *{$p->categoryName}*.",
            TransactionType::Income => "✅ Recorded {$amount} income under *{$p->categoryName}*.",
            TransactionType::Transfer => "✅ Recorded a transfer of {$amount} from *{$p->accountName}* to *{$p->toAccountName}*.",
            TransactionType::EmiPayment => "✅ Recorded an EMI of {$amount} to *{$p->toAccountName}*: principal ".Money::ofMinor($p->money->minor - $p->interestMinor, $p->money->currency)->format().', interest '.Money::ofMinor($p->interestMinor, $p->money->currency)->format().'.',
            TransactionType::CcPayment => "✅ Recorded a card payment of {$amount} from *{$p->accountName}* to *{$p->toAccountName}*. This is not counted as an expense.",
            TransactionType::Lend => "✅ Recorded {$amount} lent to *{$p->counterpartyName}*. This is not counted as an expense.".($p->dueOn ? "\nDue back: ".CarbonImmutable::parse($p->dueOn)->format('j M') : ''),
            TransactionType::Borrow => "✅ Recorded {$amount} borrowed from *{$p->counterpartyName}*. This is not counted as income.".($p->dueOn ? "\nDue back: ".CarbonImmutable::parse($p->dueOn)->format('j M') : ''),
            TransactionType::RepaymentIn => "✅ Recorded {$amount} received back from *{$p->counterpartyName}*.",
            TransactionType::RepaymentOut => "✅ Recorded {$amount} paid back to *{$p->counterpartyName}*.",
            TransactionType::SplitExpense => "✅ Recorded {$amount} for *{$p->categoryName}*, split: ".$p->splitSummary().'.',
            default => "✅ Recorded {$amount}.",
        };

        $parts = [];
        if (! in_array($p->type, [TransactionType::Transfer, TransactionType::CcPayment], true)) {
            $parts[] = (in_array($p->type, [TransactionType::Income, TransactionType::Borrow, TransactionType::RepaymentIn], true) ? 'Into: ' : 'From: ').$p->accountName;
        }
        $parts[] = 'Date: '.($p->isToday ? 'Today' : CarbonImmutable::parse($p->occurredOn)->format('j M (D)'));

        $note = $p->type === TransactionType::Transfer ? "\nThis only records the move in your books; no money was actually sent." : '';

        return $first."\n".implode(' • ', $parts).$note;
    }

    public function help(): string
    {
        return <<<'TXT'
You can say:

"spent 250 on vegetables"
"salary 45000"
"transfer 1000 from SBI to HDFC"
"aaj 200 sabji" or "500 petrol"
"balance" to see your accounts
"add Axis bank with 12000" or "opening balance on HDFC is 52340"
"gave Rahul 2000" or "Rahul returned 500"
"dinner 2400 split with Rahul and Amit"
"paid HDFC card 12000 from HDFC bank"
"who owes me?"
"set a budget of 5000 for food" or "how are my budgets?"
"Netflix 649 every month", "what are my subscriptions?"
"save 100000 for a bike by June", "transfer 5000 to bike", "how are my goals?"
"bike loan 120000 at 10% for 24 months", "paid bike EMI"
A voice note or a photo of a receipt (I'll always ask you to confirm)

Ask me:
"how much did I spend this month?"
"food spending last week"
"monthly report" or "compare this month with last month"
"biggest expense" or "show uber transactions"
"export my transactions" (CSV file)

Made a mistake?
"undo" removes the last entry
"actually that was 600" or "that was yesterday" changes it

I record expenses, income and transfers between your own accounts. Investments are coming soon.
TXT;
    }

    public function balances(User $user, AccountService $accounts): string
    {
        $balances = $accounts->balances($user->id);
        $lines = ['💰 *Balances*'];
        $assets = Money::zero($user->base_currency);
        $liabilities = Money::zero($user->base_currency);

        foreach (LedgerAccount::where('user_id', $user->id)->where('status', 'active')->orderBy('name')->get() as $a) {
            if (! $a->subtype->isUserFacing()) {
                continue;
            }
            $b = $balances[$a->id];
            if ($a->kind === AccountKind::Liability) {
                $lines[] = "{$a->name}: you owe ".$b->format();
                $liabilities = $liabilities->add($b);
            } else {
                $lines[] = "{$a->name}: ".$b->format();
                $assets = $assets->add($b);
            }
        }

        // Money between people counts toward net worth (docs/ledger.md section 5): what you are owed, and what you owe.
        $owedToYou = Money::zero($user->base_currency);
        $youOwe = Money::zero($user->base_currency);
        foreach (LedgerAccount::where('user_id', $user->id)->whereIn('subtype', ['receivable', 'payable'])->get() as $a) {
            $b = $balances[$a->id] ?? Money::zero($user->base_currency);
            $a->subtype->value === 'receivable' ? $owedToYou = $owedToYou->add($b) : $youOwe = $youOwe->add($b);
        }
        if (! $owedToYou->isZero() || ! $youOwe->isZero()) {
            $lines[] = '';
            $owedToYou->isZero() || $lines[] = 'Owed to you: '.$owedToYou->format();
            $youOwe->isZero() || $lines[] = 'You owe people: '.$youOwe->format();
            $assets = $assets->add($owedToYou);
            $liabilities = $liabilities->add($youOwe);
        }

        $lines[] = '';
        $lines[] = '*Net worth:* '.$assets->subtract($liabilities)->format();

        return implode("\n", $lines);
    }

    public function undone(string $description, bool $wasCorrection = false): string
    {
        return "↩️ Undid {$description}."
            .($wasCorrection ? "\nThat entry was a correction, so the earlier version stays removed. Tell me what it should be and I'll record it again." : '');
    }

    public function corrected(string $before, string $after): string
    {
        return "✏️ Updated.\nWas: {$before}\nNow: {$after}";
    }

    public function cancelled(): string
    {
        return 'Okay, cancelled. Nothing was recorded or changed.';
    }

    public function expired(): string
    {
        return 'That request has expired or was already handled. Please send your message again.';
    }

    public function nothingToUndo(): string
    {
        return "I couldn't find that transaction, so nothing was changed.";
    }

    public function givingUp(): string
    {
        return "I couldn't reliably understand that right now. Nothing was recorded. Please try again, for example \"spent 500 on groceries\".";
    }

    public function dailyLimit(): string
    {
        return "You've reached today's limit for smart message reading, so nothing was recorded. Please try again tomorrow.";
    }

    public function notUnderstood(): string
    {
        return 'I couldn\'t understand that. Nothing was recorded. Try something like "spent 250 on vegetables". Send "help" for more examples.';
    }
}
