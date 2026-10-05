<?php

use App\Models\AiRequest;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\AI\AIProvider;
use App\Services\AI\DTO\StructuredResponse;
use App\Services\AI\Eval\EvalRunner;
use App\Services\AI\FakeAIProvider;

beforeEach(function () {
    FakeAIProvider::reset();
    $this->cases = require base_path('tests/Evals/cases.php');
    $this->runner = fn () => app(EvalRunner::class);
});

describe('the dataset', function () {
    it('is well-formed with unique ids', function () {
        $ids = array_column($this->cases, 'id');

        expect($ids)->toBe(array_values(array_unique($ids)))->and(count($ids))->toBeGreaterThanOrEqual(50);
        foreach ($this->cases as $c) {
            expect($c)->toHaveKeys(['id', 'text', 'model', 'expect']);
            expect(trim($c['text']))->not->toBe('');
        }
    });

    it('contains every example listed in the product spec (section 80)', function () {
        $texts = array_column($this->cases, 'text');

        foreach (['spent 200 sabji', 'gave Rahul 500', 'Rahul returned 200', 'borrowed 1000 from Amit', 'paid HDFC card 5000',
            'bought shoes 3000 using credit card', 'salary 45000', 'Netflix 649 every month', 'spent 500 yesterday', 'transfer 1000 from SBI to HDFC'] as $example) {
            expect($texts)->toContain($example);
        }
    });

    it('covers the hard categories: Hinglish, Devanagari, ambiguity, injection, hallucination, credit cards, dates', function () {
        $ids = implode(' ', array_column($this->cases, 'id'));

        foreach (['hinglish', 'devanagari', 'missing-category', 'injection', 'hallucinated', 'cc-bill', 'last-friday', 'invented-amount', 'transfer-to-person'] as $needle) {
            expect($ids)->toContain($needle);
        }
    });

    it('never expects a credit-card payment, lending, borrowing or repayment to be recorded as an expense or income', function () {
        foreach ($this->cases as $c) {
            if (in_array($c['model']['items'][0]['event_type'] ?? null, ['credit_card_payment', 'lend', 'borrow', 'repayment_received'], true)) {
                // lending and borrowing need a tap; a repayment may record, but only as a repayment
                expect($c['expect']['type'] ?? null)->not->toBeIn(['expense', 'income']);
                if (in_array($c['model']['items'][0]['event_type'], ['credit_card_payment', 'lend', 'borrow'], true)) {
                    expect($c['expect']['kind'])->not->toBe('record');
                }
            }
        }
    });

    it('expects nothing to be recorded for any prompt-injection case', function () {
        foreach ($this->cases as $c) {
            if (str_contains($c['id'], 'injection') && ! str_contains($c['id'], 'in-description')) {
                // lending and borrowing need a tap; a repayment may record, but only as a repayment
                expect($c['expect']['type'] ?? null)->not->toBeIn(['expense', 'income']);
                if (in_array($c['model']['items'][0]['event_type'], ['credit_card_payment', 'lend', 'borrow'], true)) {
                    expect($c['expect']['kind'])->not->toBe('record');
                }
            }
        }
    });

    it('expects a Confirm tap for every transfer and every large amount', function () {
        foreach ($this->cases as $c) {
            $type = $c['model']['items'][0]['event_type'] ?? null;
            if ($type === 'transfer' && ($c['expect']['kind'] ?? '') === 'record') {
                $this->fail("case {$c['id']} would record a transfer without confirmation");
            }
        }
        expect(collect($this->cases)->firstWhere('id', 'large-expense-needs-confirmation')['expect']['kind'])->toBe('confirm');
    });

    it('covers undo and every kind of correction', function () {
        $ids = implode(' ', array_column($this->cases, 'id'));

        foreach (['undo-last', 'undo-by-amount', 'correct-amount', 'correct-category', 'correct-date', 'correct-account'] as $needle) {
            expect($ids)->toContain($needle);
        }
    });
});

describe('replay mode (what CI runs)', function () {
    it('passes the whole dataset with no false or wrong records', function () {
        $r = ($this->runner)()->run($this->cases, 'replay');

        expect($r->failures)->toBe([])->and($r->passed)->toBe($r->total)->and($r->falseRecords)->toBe(0)->and($r->wrongRecords)->toBe(0)
            ->and($r->accuracy())->toBe(1.0)->and($r->passes(1.0))->toBeTrue();
    });

    it('leaves no data behind', function () {
        $users = User::count();

        ($this->runner)()->run($this->cases, 'replay');

        expect(User::count())->toBe($users)->and(LedgerTransaction::count())->toBe(0)->and(AiRequest::count())->toBe(0);
    });

    it('is exposed as an artisan command that exits 0 when the gate passes', function () {
        $this->artisan('moneytalks:ai:eval')->expectsOutputToContain('Eval gate passed')->assertSuccessful();
    });

    it('can filter cases and print machine-readable JSON', function () {
        $this->artisan('moneytalks:ai:eval', ['--filter' => 'injection', '--json' => true])->expectsOutputToContain('"false_records": 0')->assertSuccessful();
        $this->artisan('moneytalks:ai:eval', ['--filter' => 'no-such-case'])->assertFailed();
    });
});

describe('the gate has teeth', function () {
    it('counts a FALSE record when something is recorded that must not be', function () {
        $bad = [['id' => 'x', 'text' => 'spent 250 on vegetables', 'model' => $this->cases[0]['model'], 'expect' => ['kind' => 'clarify']]];

        $r = ($this->runner)()->run($bad, 'replay');

        expect($r->falseRecords)->toBe(1)->and($r->passes(0.0))->toBeFalse()->and($r->failures[0]['problems'][0])->toContain('expected clarify, got record');
    });

    it('counts a WRONG record when the amount, category, account or date differs', function (string $field, mixed $wrong) {
        $case = $this->cases[0];                                     // expense-basic: 250, Vegetables, Cash, 2026-10-04
        $case['expect'][$field] = $wrong;

        $r = ($this->runner)()->run([$case], 'replay');

        expect($r->wrongRecords)->toBe(1)->and($r->falseRecords)->toBe(0)->and($r->passes(0.0))->toBeFalse();
    })->with([['amount_minor', 99900], ['category', 'Fuel'], ['account', 'HDFC Bank'], ['date', '2026-10-03'], ['type', 'income']]);

    it('counts a missed record without calling it harmful', function () {
        $case = $this->cases[0];
        $case['model']['items'][0]['category'] = null;
        $case['model']['items'][0]['missing_fields'] = ['category'];

        $r = ($this->runner)()->run([$case], 'replay');

        expect($r->missedRecords)->toBe(1)->and($r->falseRecords)->toBe(0)->and($r->passes(1.0))->toBeFalse();
    });

    it('fails the gate when accuracy is below the minimum even with no harmful records', function () {
        $case = $this->cases[0];
        $good = ($this->runner)()->run([$case, $case], 'replay');
        $case2 = $case;
        $case2['expect']['reason'] = 'something_else';
        $slip = ($this->runner)()->run([$case, $case2], 'replay');

        expect($good->passes(1.0))->toBeTrue()->and($slip->accuracy())->toBe(0.5)->and($slip->passes(0.9))->toBeFalse()->and($slip->passes(0.5))->toBeTrue();
    });

    it('reports structurally invalid model output as a failure, not a crash', function () {
        $case = ['id' => 'junk', 'text' => 'x', 'model' => ['language' => 'en'], 'expect' => ['kind' => 'unsupported']];

        $r = ($this->runner)()->run([$case], 'replay');

        expect($r->total)->toBe(1)->and($r->passed)->toBe(0)->and($r->failures[0]['problems'][0])->toContain('structurally invalid');
    });

    it('makes the artisan command exit non-zero when the gate fails', function () {
        // A prompt/validator regression would look like this: break the validator's amount cross-check.
        config(['ai.risk.auto_commit_min_score' => 0.9999]);

        $this->artisan('moneytalks:ai:eval')->expectsOutputToContain('Eval gate FAILED')->assertFailed();
    });

    it('treats a scripted multi-item expectation as one case with several decisions', function () {
        $two = collect($this->cases)->firstWhere('id', 'two-records');

        $r = ($this->runner)()->run([$two], 'replay');

        expect($r->passed)->toBe(1)->and($r->kindCorrect)->toBe(1);
    });
});

describe('live mode (real model, real money)', function () {
    it('refuses to run without a real provider and key', function () {
        config(['ai.provider' => 'fake']);

        $this->artisan('moneytalks:ai:eval', ['--live' => true, '--yes' => true])->expectsOutputToContain('Live mode needs')->assertFailed();
    });

    it('asks before spending money', function () {
        config(['ai.provider' => 'anthropic', 'ai.anthropic.api_key' => 'sk-test']);

        $this->artisan('moneytalks:ai:eval', ['--live' => true, '--filter' => 'expense-basic'])
            ->expectsConfirmation('Spend this money?', 'no')->assertFailed();
        expect(FakeAIProvider::$requests)->toBe([]);
    });

    it('sends only the message and context to the model (never the expected answer) and reports tokens, latency and cost', function () {
        config(['ai.provider' => 'anthropic', 'ai.anthropic.api_key' => 'sk-test']);
        app()->instance(AIProvider::class, new FakeAIProvider);
        FakeAIProvider::responder(fn () => new StructuredResponse(
            aiEnvelope(aiItem()), json_encode(aiEnvelope(aiItem())), 'claude-haiku-4-5', 'ok', 1700, 200, 0, 0, 'msg_1', 420,
        ));

        $report = app(EvalRunner::class)->run([collect($this->cases)->firstWhere('id', 'expense-basic')], 'live');

        expect(FakeAIProvider::$requests)->toHaveCount(1)
            ->and(FakeAIProvider::$requests[0]->user)->toContain('spent 250 on vegetables')
            ->and(FakeAIProvider::$requests[0]->user)->not->toContain('"intent"')       // the fixture answer is never sent
            ->and($report->passed)->toBe(1)->and($report->inputTokens)->toBe(1700)->and($report->outputTokens)->toBe(200)
            ->and($report->percentile(50))->toBe(420)
            ->and($report->costMicros)->toBe(1700 + 1000);                               // $1/MTok in, $5/MTok out
    });

    it('records a model that fails or refuses as a failed case, never as a record', function () {
        config(['ai.provider' => 'anthropic', 'ai.anthropic.api_key' => 'sk-test']);
        app()->instance(AIProvider::class, new FakeAIProvider);
        FakeAIProvider::responder(fn () => new StructuredResponse(null, '', 'claude-haiku-4-5', 'refusal', 100, 0));

        $report = app(EvalRunner::class)->run([collect($this->cases)->firstWhere('id', 'expense-basic')], 'live');

        expect($report->passed)->toBe(0)->and($report->falseRecords)->toBe(0)->and($report->failures)->toHaveCount(1);
    });

    it('writes a report file after a live run', function () {
        config(['ai.provider' => 'anthropic', 'ai.anthropic.api_key' => 'sk-test']);
        app()->instance(AIProvider::class, new FakeAIProvider);
        FakeAIProvider::responder(fn () => aiEnvelope(aiItem()));
        $before = glob(storage_path('app/evals/*.json')) ?: [];

        $this->artisan('moneytalks:ai:eval', ['--live' => true, '--yes' => true, '--filter' => 'expense-basic'])->assertSuccessful();

        $after = glob(storage_path('app/evals/*.json')) ?: [];
        $new = array_diff($after, $before);
        expect($new)->toHaveCount(1);
        array_map('unlink', $new);
    });
});
