<?php

namespace App\Services\WhatsApp\Handlers;

use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\LedgerService;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Accounts\AccountSetupService;
use App\Services\Budgets\BudgetService;
use App\Services\Conversation\ConversationStore;
use App\Services\Conversation\PendingActionService;
use App\Services\Goals\GoalService;
use App\Services\Interpretation\CommandBuilder;
use App\Services\Interpretation\CorrectionPlanner;
use App\Services\Interpretation\Decision;
use App\Services\Interpretation\DuplicateDetector;
use App\Services\Interpretation\FollowUpParser;
use App\Services\Interpretation\InterpretationService;
use App\Services\Interpretation\ProposalValidator;
use App\Services\Interpretation\ProposedPosting;
use App\Services\Interpretation\TransactionLocator;
use App\Services\Media\ReceiptService;
use App\Services\Ops\AiSwitch;
use App\Services\Recurring\RecurringService;
use App\Services\Reporting\ExportService;
use App\Services\Reporting\QueryPlanner;
use App\Services\Reporting\ReportRunner;
use App\Services\Speech\Exceptions\SpeechException;
use App\Services\Speech\SpeechToTextProvider;
use App\Services\WhatsApp\DTO\InboundMessage;
use App\Services\WhatsApp\Exceptions\MediaException;
use App\Services\WhatsApp\MediaGuard;
use App\Services\WhatsApp\OutboundMessageService;
use App\Services\WhatsApp\ReplyFormatter;
use App\Services\WhatsApp\WhatsAppProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * message -> (button | pending follow-up | deterministic shortcut | AI proposal) -> validation -> ledger -> reply.
 * Idempotent for retries: ledger keys are "{wa_message_id}:{item}" or "confirm:{state id}", replies use
 * "reply:{wa_message_id}:0".
 */
class InterpretationHandler implements InboundHandler
{
    private const YES = ['yes', 'y', 'yeah', 'yep', 'ok', 'okay', 'confirm', 'sure', 'ha', 'haa', 'haan', 'ji', 'हाँ', 'हां', 'हा'];

    private const NO = ['no', 'n', 'nope', 'nahi', 'nahin', 'cancel', 'stop', 'keep', 'नहीं', 'नही'];

    public function __construct(
        private readonly InterpretationService $interpretation,
        private readonly LedgerService $ledger,
        private readonly AccountService $accounts,
        private readonly OutboundMessageService $out,
        private readonly ReplyFormatter $replies,
        private readonly ConversationStore $store,
        private readonly PendingActionService $pending,
        private readonly FollowUpParser $followUp,
        private readonly ProposalValidator $validator,
        private readonly DuplicateDetector $duplicates,
        private readonly TransactionLocator $locator,
        private readonly CorrectionPlanner $planner,
        private readonly QueryPlanner $queryPlanner,
        private readonly ReportRunner $reportRunner,
        private readonly ExportService $exports,
        private readonly CommandBuilder $builder,
        private readonly BudgetService $budgets,
        private readonly RecurringService $recurring,
        private readonly GoalService $goals,
        private readonly AccountSetupService $accountSetup,
        private readonly WhatsAppProvider $provider,
        private readonly MediaGuard $mediaGuard,
        private readonly SpeechToTextProvider $stt,
        private readonly ReceiptService $receipts,
        private readonly AiSwitch $aiSwitch,
    ) {}

    public function handle(User $user, InboundMessage $message, WhatsappMessage $row): void
    {
        $reply = $this->reply($user, $message, $row);
        $key = "reply:{$message->waMessageId}:0";

        try {
            if ($reply->document !== null) {
                // The file goes first; any text (e.g. "nothing to export" never reaches here) follows.
                $this->out->sendDocument($user, $reply->document['path'], $reply->document['filename'], $reply->document['caption'], "reply:{$message->waMessageId}:doc", $message->waMessageId);
                if (trim($reply->text) !== '') {
                    $this->out->sendText($user, $reply->text, $key, $message->waMessageId);
                }

                return;
            }

            $reply->buttons === []
                ? $this->out->sendText($user, $reply->text, $key, $message->waMessageId)
                : $this->out->sendButtons($user, $reply->text, $reply->buttons, $key, $message->waMessageId);
        } finally {
            if ($reply->document !== null) {
                @unlink($reply->document['path']);
            }
        }
    }

    private function reply(User $user, InboundMessage $message, WhatsappMessage $row): HandlerReply
    {
        if (in_array($message->type, ['interactive', 'button'], true)) {
            return HandlerReply::text($this->onButton($user, $message));
        }
        if (in_array($message->type, ['audio', 'image'], true)) {
            return $this->media($user, $message, $row);
        }
        if ($message->type !== 'text' || trim((string) $message->text) === '') {
            return HandlerReply::text('I can read text, voice notes and photos of receipts for now.');
        }

        return $this->converse($user, $message, $row, trim($message->text), 'whatsapp_text');
    }

    /** A voice note or a receipt photo. Both end in a Confirm tap: transcripts and photos can be misread. */
    private function media(User $user, InboundMessage $message, WhatsappMessage $row): HandlerReply
    {
        $audio = $message->type === 'audio';
        if ($this->aiSwitch->blocked()) {
            return HandlerReply::text($this->aiSwitch->message());
        }
        if ($audio && ! $this->stt->enabled()) {
            return HandlerReply::text('🎙️ Voice notes aren\'t switched on yet. Please type it, for example "spent 250 on vegetables".');
        }

        try {
            $max = $this->mediaGuard->admit($user, $message);
            $file = $this->provider->downloadMedia((string) $message->mediaId, $max);
            $this->mediaGuard->assertContent($file);
        } catch (MediaException $e) {
            if ($e->retryable) {
                throw $e; // the reaper retries the event; the user's message is not lost
            }

            return HandlerReply::text(match ($e->reason) {
                'daily_limit' => 'You have reached today\'s limit for voice notes and photos. Please type it instead.',
                'too_large' => 'That file is too large for me to read. Please send a smaller one, or type it.',
                'unsupported_type' => 'I can\'t read that kind of file. Send a voice note, a photo of a receipt, or type it.',
                default => 'I couldn\'t download that. Please send it again, or type it.',
            });
        }
        $now = CarbonImmutable::now('UTC');

        if ($audio) {
            try {
                $transcript = $this->stt->transcribe($file->bytes, $file->mimeType);
            } catch (SpeechException $e) {
                if ($e->retryable) {
                    throw $e;
                }

                return HandlerReply::text('🎙️ I couldn\'t make out that voice note. Please try again, or type it.');
            }

            return $this->converse($user, $message, $row, $transcript, 'whatsapp_voice')->withPrefix("🎙️ I heard: “{$transcript}”");
        }

        $caption = trim((string) $message->text);
        $result = $this->receipts->read($user, $file, $caption, (string) $row->id, $now);
        if (is_string($result)) {
            return HandlerReply::text($result);
        }

        return $this->decisions($user, [$result], $message, trim(($result->item['amount'] ?? '').' '.$caption), 0, $now, 'whatsapp_image')->withPrefix('🧾 I read your receipt:');
    }

    private function converse(User $user, InboundMessage $message, WhatsappMessage $row, string $text, string $source): HandlerReply
    {
        $word = mb_strtolower(trim($text, " \t\n\r/?!.,"), 'UTF-8');
        $now = CarbonImmutable::now('UTC');
        $state = $this->store->current($user);
        $droppedConfirm = false;

        // A pending item shapes how this message is read.
        if ($state) {
            if (in_array($word, self::NO, true)) {
                return HandlerReply::text($this->pending->cancel($user));
            }
            if ($state->kind === ConversationStore::CONFIRM && in_array($word, self::YES, true)) {
                return HandlerReply::text($this->pending->confirm($user, $state, $message->waMessageId));
            }
            if ($state->kind === ConversationStore::CLARIFY && ($followed = $this->followUp($user, $state, $text, $message, $now))) {
                return $followed;
            }

            // Something unrelated: the pending item is dropped (a confirmation is cancelled explicitly, never applied).
            $droppedConfirm = $state->kind === ConversationStore::CONFIRM;
            $this->store->clear($user);
        } elseif (in_array($word, self::YES, true) || in_array($word, self::NO, true)) {
            return HandlerReply::text('There is nothing waiting for a reply right now.');
        }

        // Deterministic shortcuts: no AI call, no cost.
        if (in_array($word, ['help', 'start', 'menu', 'hi', 'hello', 'madad', 'मदद'], true)) {
            return HandlerReply::text($this->replies->help());
        }
        if (in_array($word, ['balance', 'balances', 'bal'], true)) {
            return HandlerReply::text($this->replies->balances($user, $this->accounts));
        }
        if (in_array($word, ['undo', 'undo last', 'undo that'], true)) {
            return $this->undo($user, Decision::intent(Decision::UNDO, 0, ['target_kind' => 'last']), $message, $now, $source !== 'whatsapp_text');
        }

        $result = $this->interpretation->interpret($user, $text, $row->id, $now);

        if ($result->status === 'paused') {
            return HandlerReply::text($this->aiSwitch->message());
        }
        if ($result->status === 'daily_limit') {
            return HandlerReply::text($this->replies->dailyLimit());
        }
        if ($result->status === 'invalid_output') {
            Log::warning('ai.invalid_output', ['detail' => $result->detail, 'ai_request_id' => $result->aiRequestId]);

            return HandlerReply::text($this->replies->givingUp());
        }

        $reply = $this->decisions($user, $result->decisions, $message, $text, 0, $now, $source);

        return $droppedConfirm ? $reply->withAppended('(Your earlier request waiting for confirmation was cancelled.)') : $reply;
    }

    /** The user answered a question we asked ("for what?"): complete the pending item without another AI call. */
    private function followUp(User $user, $state, string $answer, InboundMessage $message, CarbonImmutable $now): ?HandlerReply
    {
        $p = $state->payload;
        $patched = $this->followUp->apply($user, $p['item'], $p['awaiting'], $answer);
        if ($patched === null) {
            return null;
        }

        $decision = $this->validator->decideItem($user, $patched, (int) $p['index'], $p['text'].' '.$answer, $now);
        $this->store->clear($user);

        return $this->decisions($user, [$decision], $message, $p['text'], $state->turns + 1, $now, (string) ($p['source'] ?? 'whatsapp_text'));
    }

    /**
     * @param  list<Decision>  $decisions
     */
    private function decisions(User $user, array $decisions, InboundMessage $message, string $text, int $turns, CarbonImmutable $now, string $source = 'whatsapp_text'): HandlerReply
    {
        $lines = [];
        $buttons = [];
        $document = null;
        $waiting = false; // only ONE pending item per user: later ones must be sent again afterwards

        $ask = function (string $kind, array $payload, string $prompt, ?array $titles = null) use ($user, $message, $turns, &$buttons, &$waiting): string {
            if ($waiting) {
                return 'I\'ll need to ask about another part of that separately. Please send it again after answering the question above.';
            }
            $waiting = true;
            $state = $this->store->put($user, $kind, $payload, $message->waMessageId, $turns);
            if ($titles) {
                $buttons = [['id' => "confirm:{$state->id}", 'title' => $titles[0]], ['id' => "cancel:{$state->id}", 'title' => $titles[1]]];
            }

            return $prompt;
        };

        foreach ($decisions as $d) {
            $lines[] = match ($d->kind) {
                Decision::RECORD => $source === 'whatsapp_text'
                    ? $this->recordOrQueryDuplicate($user, $d, $message, $ask)
                    : $ask(ConversationStore::CONFIRM, PendingActionService::payload(PendingActionService::RECORD, 'Record '.$d->posting->describe().'?', $message->waMessageId, $d->posting->withSource($source)), 'Record '.$d->posting->describe().'?', ['Confirm', 'Cancel']),
                Decision::CONFIRM => $d->reason === 'correction'
                    ? $ask(ConversationStore::CONFIRM, PendingActionService::payload(PendingActionService::CORRECT, $d->message, $message->waMessageId, $d->posting, ['tx_id' => $d->item['correct_tx_id']]), $d->message, ['Apply', 'Cancel'])
                    : $ask(ConversationStore::CONFIRM, PendingActionService::payload(PendingActionService::RECORD, $d->message, $message->waMessageId, $d->posting->withSource($source)), $d->message, ['Confirm', 'Cancel']),
                Decision::CLARIFY => $this->clarify($d, $text, $turns, $ask, $source),
                Decision::UNDO => $this->undoLine($user, $d, $message, $now, $ask, $source !== 'whatsapp_text'),
                Decision::CORRECT => $this->correctLine($user, $d, $message, $text, $now, $ask),
                Decision::QUERY => $this->queryLine($user, $d, $now),
                Decision::RECURRING => $this->planLine($user, $d, $message, $text, $source, $now, $ask),
                Decision::LOAN, Decision::ACCOUNT, Decision::GOAL, Decision::BUDGET => $this->planLine($user, $d, $message, $text, $source, $now, $ask),
                Decision::EXPORT => $this->exportLine($user, $d, $now, $document),
                Decision::HELP => $this->replies->help(),
                default => $d->message,
            };
        }

        return new HandlerReply(implode("\n\n", array_filter($lines)), $buttons, $document);
    }

    /** A question or report: planned from the model's item, answered by SQL, formatted by templates. No AI after this point. */
    private function queryLine(User $user, Decision $d, CarbonImmutable $now): string
    {
        [$query, $decision] = $this->queryPlanner->plan($user, $d->item ?? [], $d->index, $now);

        return $query ? $this->reportRunner->run($user, $query) : $decision->message;
    }

    private function exportLine(User $user, Decision $d, CarbonImmutable $now, ?array &$document): string
    {
        $format = $d->item['export_format'] ?? 'csv';
        if ($format !== 'csv') {
            return 'I can only export CSV files for now (they open in Excel and Google Sheets). Ask me to "export my transactions" and I\'ll send one.';
        }
        [$query, $decision] = $this->queryPlanner->plan($user, ['query_metric' => 'list'] + $d->item, $d->index, $now);
        if (! $query) {
            return $decision->message;
        }

        $filters = $query->hasFilters() ? $query : null;
        $file = $this->exports->csv($user, $query->period, $filters);
        if ($file['rows'] === 0) {
            @unlink($file['path']);

            return "Nothing to export for {$query->period->label}".($query->hasFilters() ? ' with those filters' : '').'.';
        }

        $document = ['path' => $file['path'], 'filename' => $file['filename'], 'caption' => "{$file['rows']} transactions · {$query->period->label}"];

        return $file['truncated'] ? 'The file has the most recent '.number_format($file['rows']).' transactions only; ask for a shorter period to get the rest.' : '';
    }

    private function recordOrQueryDuplicate(User $user, Decision $d, InboundMessage $message, \Closure $ask): string
    {
        $p = $d->posting;
        $key = "{$message->waMessageId}:{$d->index}";

        if ($similar = $this->duplicates->find($user, $p, $key)) {
            $prompt = 'This looks similar to a transaction you recorded '.$similar->created_at->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE).' ago ('.$this->locator->describe($similar).'). Record it anyway?';

            return $ask(ConversationStore::CONFIRM, PendingActionService::payload(PendingActionService::RECORD, $prompt, $message->waMessageId, $p), $prompt, ['Yes, record it', 'No']);
        }

        try {
            $posted = $this->ledger->post($this->builder->build($user, $p, $key, $message->waMessageId));
        } catch (LedgerException $e) {
            // The validator should have caught this; if the ledger still refuses, nothing was written.
            Log::warning('ledger.refused_validated_posting', ['reason' => $e->getMessage()]);

            return "I couldn't record that: {$e->getMessage()} Nothing was recorded.";
        }

        $linked = $p->type === TransactionType::Expense && ! $posted->replayed ? $this->recurring->linkIfMatches($user, $posted->transaction, $p->categoryId) : null;

        return $this->replies->recorded($p).($linked ? "\n".$linked : '').$this->budgetAlerts($user, $p);
    }

    private function budgetAlerts(User $user, ProposedPosting $p): string
    {
        if ($p->type !== TransactionType::Expense && $p->type !== TransactionType::SplitExpense) {
            return '';
        }
        $lines = $this->budgets->alertsAfterExpense($user, $p->categoryId, $p->occurredOn, CarbonImmutable::now('UTC'));

        return $lines === [] ? '' : "\n\n".implode("\n", $lines);
    }

    private function clarify(Decision $d, string $text, int $turns, \Closure $ask, string $source = 'whatsapp_text'): string
    {
        $max = (int) config('moneytalks.conversation.max_clarify_turns');

        if ($d->awaiting === null || $d->item === null) {
            return $d->message;
        }
        if ($turns + 1 > $max) {
            return $d->message."\n\nLet's start over: please send the whole thing again, for example \"spent 500 on groceries\".";
        }

        return $ask(ConversationStore::CLARIFY, ['item' => $d->item, 'text' => $text, 'awaiting' => $d->awaiting, 'index' => $d->index, 'source' => $source], $d->message);
    }

    private function undo(User $user, Decision $d, InboundMessage $message, CarbonImmutable $now, bool $alwaysAsk = false): HandlerReply
    {
        $buttons = [];
        $line = $this->undoLine($user, $d, $message, $now, function (string $kind, array $payload, string $prompt, ?array $titles = null) use ($user, $message, &$buttons) {
            $state = $this->store->put($user, $kind, $payload, $message->waMessageId);
            $buttons = [['id' => "confirm:{$state->id}", 'title' => $titles[0]], ['id' => "cancel:{$state->id}", 'title' => $titles[1]]];

            return $prompt;
        }, $alwaysAsk);

        return new HandlerReply($line, $buttons);
    }

    /**
     * Budgets, goals, loans and recurring payments: a loan always needs a tap (it writes an opening balance to the ledger),
     * and so does everything when it came from a transcript or a photo. Otherwise it is applied straight away.
     */
    private function planLine(User $user, Decision $d, InboundMessage $message, string $text, string $source, CarbonImmutable $now, \Closure $ask): string
    {
        $item = (array) $d->item;
        if (! in_array($d->kind, [Decision::LOAN, Decision::ACCOUNT], true) && $source === 'whatsapp_text') {
            return match ($d->kind) {
                Decision::GOAL => $this->goals->apply($user, $item, $text, $now),
                Decision::BUDGET => $this->budgets->apply($user, $item, $text, $now),
                default => isset($item['rr_remove']) ? $this->recurring->cancel($user, $item['rr_remove']) : $this->recurring->create($user, $item['rr']),
            };
        }

        $prompt = $d->kind === Decision::ACCOUNT ? $this->accountSetup->describe($user, (array) $item['acct']) : $this->planPrompt($d->kind, $item);

        return $ask(ConversationStore::CONFIRM, PendingActionService::payload(PendingActionService::APPLY, $prompt, $message->waMessageId, null, ['intent' => $d->kind, 'item' => $item, 'text' => $text]), $prompt, ['Confirm', 'Cancel']);
    }

    /** @param array<string, mixed> $item */
    private function planPrompt(string $kind, array $item): string
    {
        $amount = isset($item['amount']) ? '₹'.$item['amount'] : 'the amount you said';
        $name = mb_convert_case(trim((string) ($item['description'] ?? $item['category'] ?? $item['merchant'] ?? '')), MB_CASE_TITLE, 'UTF-8');
        $remove = ($item['action'] ?? null) === 'remove' || isset($item['rr_remove']);

        return match (true) {
            $kind === Decision::LOAN => 'Start tracking the '.($name ?: 'new').' loan: '.$amount.' outstanding'.(isset($item['interest_rate']) ? ' at '.$item['interest_rate'].'%' : '').(isset($item['tenure_months']) ? ' for '.$item['tenure_months'].' months' : '').'?',
            $kind === Decision::GOAL && $remove => 'Stop the '.($name ?: 'savings').' goal?',
            $kind === Decision::GOAL => 'Set a savings goal'.($name ? " \"{$name}\"" : '').' of '.$amount.'?',
            $kind === Decision::BUDGET && $remove => 'Remove the '.($item['category'] ?? 'overall').' budget?',
            $kind === Decision::BUDGET => 'Set the '.($item['category'] ?? 'overall').' budget to '.$amount.' per month?',
            $remove => 'Stop the recurring payment '.($item['rr_remove'] ?? $name).'?',
            default => 'Repeat '.($item['rr']['name'] ?? $name).' '.($item['rr']['frequency'] ?? '').' from '.CarbonImmutable::parse($item['rr']['first_due'] ?? 'today')->format('j M').'?',
        };
    }

    private function undoLine(User $user, Decision $d, InboundMessage $message, CarbonImmutable $now, \Closure $ask, bool $alwaysAsk = false): string
    {
        $matches = $this->locator->find($user, $d->item ?? [], $now);
        if ($matches->isEmpty()) {
            return $this->replies->nothingToUndo();
        }
        /** @var LedgerTransaction $tx */
        $tx = $matches->first();
        $recentLast = ($d->item['target_kind'] ?? 'last') === 'last' && $tx->created_at->greaterThan(now()->subDay());

        // "undo" straight after recording something is instant; anything less obvious asks first.
        if ($recentLast && ! $alwaysAsk) { // a transcript or a photo never undoes anything without a tap
            try {
                $description = $this->locator->describe($tx);
                $this->ledger->reverse($user->id, $tx->id, 'undo requested via WhatsApp', TransactionSource::WhatsappText, $message->waMessageId);

                return $this->replies->undone($description, $tx->corrects_id !== null);
            } catch (LedgerException $e) {
                return "I couldn't undo that: {$e->getMessage()}";
            }
        }

        $prompt = 'Undo '.$this->locator->describe($tx).'?'.($matches->count() > 1 ? " (I found {$matches->count()} similar entries; this is the most recent.)" : '');

        return $ask(ConversationStore::CONFIRM, PendingActionService::payload(PendingActionService::UNDO, $prompt, $message->waMessageId, null, ['tx_id' => $tx->id]), $prompt, ['Undo it', 'Keep it']);
    }

    private function correctLine(User $user, Decision $d, InboundMessage $message, string $text, CarbonImmutable $now, \Closure $ask): string
    {
        $matches = $this->locator->find($user, $d->item, $now);
        if ($matches->isEmpty()) {
            return "I couldn't find the transaction you want to change, so nothing was changed.";
        }

        $plan = $this->planner->plan($user, $d->item, $matches->first(), $text, $now, $d->index);

        if ($plan->kind === Decision::CONFIRM) {
            $note = $matches->count() > 1 ? "\n(I found {$matches->count()} similar entries; this is the most recent.)" : '';

            return $ask(ConversationStore::CONFIRM, PendingActionService::payload(PendingActionService::CORRECT, $plan->message, $message->waMessageId, $plan->posting, ['tx_id' => $plan->item['correct_tx_id']]), $plan->message.$note, ['Apply', 'Cancel']);
        }

        return $plan->message;
    }

    /** A tap on one of our Confirm/Cancel buttons. */
    private function onButton(User $user, InboundMessage $message): string
    {
        if (! preg_match('/^(confirm|cancel|paid|skip):(.+)$/', (string) $message->replyId, $m)) {
            return $this->replies->expired();
        }
        if ($m[1] === 'paid') {
            return $this->recurring->pay($user, $m[2], $message->waMessageId);
        }
        if ($m[1] === 'skip') {
            return $this->recurring->skip($user, $m[2]);
        }

        $state = $this->store->find($user, $m[2]);
        if (! $state) {
            return $this->replies->expired();
        }

        return $m[1] === 'confirm'
            ? $this->pending->confirm($user, $state, $message->waMessageId)
            : $this->pending->cancel($user);
    }
}
