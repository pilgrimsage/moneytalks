<?php

namespace App\Services\AI\Eval;

use App\Domain\Finance\UserProvisioner;
use App\Domain\Ledger\AccountService;
use App\Domain\Ledger\DTO\PostingCommand;
use App\Domain\Ledger\LedgerService;
use App\Enums\AccountSubtype;
use App\Enums\TransactionType;
use App\Models\Counterparty;
use App\Models\User;
use App\Models\UserAlias;
use App\Services\AI\AIProvider;
use App\Services\AI\CostCalculator;
use App\Services\AI\DTO\StructuredRequest;
use App\Services\AI\Exceptions\AIException;
use App\Services\AI\PromptRegistry;
use App\Services\AI\Prompts\TransactionParser;
use App\Services\AI\ResponseNormalizer;
use App\Services\Interpretation\Decision;
use App\Services\Interpretation\PromptContext;
use App\Services\Interpretation\ProposalValidator;
use App\Support\Money;
use App\Support\Text;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs the dataset through the real prompt builder and validator. `replay` feeds each case's recorded
 * model answer (deterministic, free: CI); `live` asks the configured provider (real accuracy, real money).
 * Everything happens inside a transaction that is rolled back: the eval never leaves data behind.
 */
class EvalRunner
{
    public const NOW = '2026-10-04 10:00:00';

    public function __construct(
        private readonly AIProvider $provider,
        private readonly PromptContext $context,
        private readonly ProposalValidator $validator,
        private readonly PromptRegistry $prompts,
        private readonly CostCalculator $costs,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $cases
     * @param  Closure(string, bool): void|null  $progress
     */
    public function run(array $cases, string $mode, ?string $model = null, ?Closure $progress = null): EvalReport
    {
        $report = new EvalReport;
        $now = CarbonImmutable::parse(self::NOW, 'Asia/Kolkata')->utc();
        $model ??= (string) config('ai.models.fast');

        DB::beginTransaction();
        try {
            $user = $this->makeUser();

            foreach ($cases as $case) {
                $report->total++;
                $problems = [];
                $decisions = [];

                try {
                    $answer = $mode === 'live'
                        ? $this->ask($user, $case['text'], $now, $model, $report)
                        : ($case['model'] ?? throw new \LogicException("case {$case['id']} has no recorded model answer"));

                    $answer = ResponseNormalizer::normalize((array) $answer); // same step the live path takes
                    $error = $this->validator->structuralError($answer);
                    $decisions = $error === null ? $this->validator->decide($user, $answer, $case['text'], $now) : [];
                    if ($error !== null) {
                        $problems[] = "structurally invalid model output: {$error}";
                    }
                } catch (Throwable $e) {
                    $report->errors++;
                    $problems[] = 'error: '.get_class($e).($e instanceof AIException && $e->detail !== null ? ' ('.$e->detail.')' : '');
                }

                $problems = array_merge($problems, $this->compare($case['expect'], $decisions, $report));
                if ($problems === []) {
                    $report->passed++;
                } else {
                    $report->failures[] = ['id' => $case['id'], 'text' => $case['text'], 'problems' => $problems, 'got' => $this->describe($decisions)];
                }
                $progress?->__invoke($case['id'], $problems === []);
            }
        } finally {
            DB::rollBack();
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function ask(User $user, string $text, CarbonImmutable $now, string $model, EvalReport $report): array
    {
        $request = new StructuredRequest($model, $this->prompts->active()->body, $this->context->build($user, $text, $now), TransactionParser::schema(), (int) config('ai.max_output_tokens'));
        $response = $this->provider->structured($request);

        $report->inputTokens += $response->inputTokens;
        $report->outputTokens += $response->outputTokens;
        $report->latenciesMs[] = $response->latencyMs;
        $report->costMicros += $this->costs->estimate($this->provider->pricingProvider(), $response)['micros'];

        return $response->data ?? ['items' => 'model returned '.$response->status];
    }

    /**
     * @param  array<string, mixed>  $expect
     * @param  list<Decision>  $decisions
     * @return list<string> problems; empty when the decisions match
     */
    private function compare(array $expect, array $decisions, EvalReport $report): array
    {
        $expected = $expect['items'] ?? [$expect];
        $problems = [];
        $allKindsRight = count($decisions) === count($expected);

        if (count($decisions) !== count($expected)) {
            $problems[] = 'expected '.count($expected).' decision(s), got '.count($decisions);
        }

        foreach ($expected as $i => $want) {
            $got = $decisions[$i] ?? null;
            if (! $got) {
                continue;
            }
            $kindOk = $got->kind === $want['kind'];
            $allKindsRight = $allKindsRight && $kindOk;

            if (! $kindOk) {
                $problems[] = "item {$i}: expected {$want['kind']}, got {$got->kind}".($got->reason ? " ({$got->reason})" : '');
                if ($got->kind === Decision::RECORD) {
                    $report->falseRecords++;      // recorded something that must not have been
                } elseif ($want['kind'] === Decision::RECORD) {
                    $report->missedRecords++;
                }

                continue;
            }
            if (isset($want['reason']) && $got->reason !== $want['reason']) {
                $problems[] = "item {$i}: expected reason {$want['reason']}, got {$got->reason}";
            }
            // A proposal that would be recorded (now, or after the user taps Confirm) must have the right details.
            if ($got->posting !== null && ($fieldProblems = $this->fields($want, $got, $i)) !== []) {
                $problems = array_merge($problems, $fieldProblems);
                $report->wrongRecords++;
            }
            if (in_array($got->kind, [Decision::UNDO, Decision::CORRECT, Decision::QUERY, Decision::EXPORT, Decision::BUDGET, Decision::RECURRING, Decision::GOAL, Decision::LOAN], true)) {
                $problems = array_merge($problems, $this->intentFields($want, $got, $i));
            }
        }

        if ($allKindsRight) {
            $report->kindCorrect++;
        }

        return $problems;
    }

    /** Names compare case-insensitively ("HDFC Credit Card" is "HDFC credit card"); everything else exactly. */
    private function sameValue(mixed $got, mixed $want): bool
    {
        return is_string($got) && is_string($want) ? mb_strtolower($got) === mb_strtolower($want) : $got === $want;
    }

    /** @return list<string> which transaction the user pointed at, and (for corrections) the new values */
    private function intentFields(array $want, Decision $got, int $i): array
    {
        $problems = [];
        foreach (['target_kind', 'target_amount', 'target_text', 'amount', 'category', 'account', 'merchant', 'query_metric', 'group_by', 'limit', 'search_text', 'export_format'] as $field) {
            if (array_key_exists($field, $want) && ! $this->sameValue($got->item[$field] ?? null, $want[$field])) {
                $problems[] = "item {$i}: {$field} expected ".json_encode($want[$field]).', got '.json_encode($got->item[$field] ?? null);
            }
        }

        foreach (['period' => 'period_kind', 'compare_period' => 'compare_kind'] as $key => $wantKey) {
            if (array_key_exists($wantKey, $want) && ($got->item[$key]['kind'] ?? null) !== $want[$wantKey]) {
                $problems[] = "item {$i}: {$key} expected ".json_encode($want[$wantKey]).', got '.json_encode($got->item[$key]['kind'] ?? null);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function fields(array $want, Decision $got, int $i): array
    {
        $p = $got->posting;
        $actual = [
            'type' => $p->type->value, 'amount_minor' => $p->money->minor, 'category' => $p->categoryName,
            'account' => $p->accountName, 'to_account' => $p->toAccountName, 'date' => $p->occurredOn,
            'counterparty' => $p->counterpartyName,
            'shares' => implode(',', array_map(fn ($s) => $s['name'].':'.$s['minor'], $p->shares)),
        ];
        $problems = [];
        foreach (['type', 'amount_minor', 'category', 'account', 'to_account', 'date', 'counterparty', 'shares'] as $field) {
            if (array_key_exists($field, $want) && $want[$field] !== $actual[$field]) {
                $problems[] = "item {$i}: {$field} expected ".json_encode($want[$field]).', got '.json_encode($actual[$field]);
            }
        }

        return $problems;
    }

    /** @param list<Decision> $decisions */
    private function describe(array $decisions): string
    {
        return collect($decisions)->map(fn (Decision $d) => $d->kind.($d->reason && $d->reason !== 'ok' ? ":{$d->reason}" : '')
            .($d->posting ? " {$d->posting->type->value} {$d->posting->money->minor} ".($d->posting->categoryName ?? '-') : ''))->implode('; ') ?: '(nothing)';
    }

    private function makeUser(): User
    {
        $user = app(UserProvisioner::class)->provision('990000000001', 'Eval');
        $svc = app(AccountService::class);

        foreach ([['HDFC Bank', AccountSubtype::Bank, ['hdfc']], ['SBI Bank', AccountSubtype::Bank, ['sbi']], ['HDFC Credit Card', AccountSubtype::CreditCard, ['cc', 'credit card', 'hdfc card']]] as [$name, $type, $aliases]) {
            $account = $svc->create($user, $name, $type);
            foreach (array_merge([$name], $aliases) as $alias) {
                UserAlias::create(['user_id' => $user->id, 'entity_type' => 'account', 'entity_id' => $account->id, 'alias' => Text::normalize($alias)]);
            }
        }
        $people = [];
        foreach (['Rahul', 'Amit'] as $name) {
            $people[$name] = Counterparty::create(['user_id' => $user->id, 'name' => $name]);
        }
        // Fixed debts so repayments can be judged: Rahul owes the user 5,000; the user owes Amit 3,000.
        $cash = $user->settings->default_account_id;
        foreach ([['Rahul', TransactionType::Lend, 500000], ['Amit', TransactionType::Borrow, 300000]] as [$who, $type, $minor]) {
            app(LedgerService::class)->post(new PostingCommand(
                userId: $user->id, type: $type, money: Money::ofMinor($minor, $user->base_currency), occurredOn: '2026-09-20',
                accountId: $cash, idempotencyKey: "eval:{$who}", counterpartyId: $people[$who]->id,
            ));
        }

        return $user->fresh();
    }
}
