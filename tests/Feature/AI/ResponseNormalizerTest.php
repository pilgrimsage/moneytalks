<?php

use App\Models\LedgerTransaction;
use App\Services\AI\FakeAIProvider;
use App\Services\AI\ResponseNormalizer;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;

/** What the model returns now: explicit empty values instead of nulls. */
function emptyItem(array $o = []): array
{
    return $o + [
        'intent' => 'query', 'event_type' => 'none', 'amount' => '', 'currency' => '',
        'date' => ['kind' => 'none', 'offset_days' => 0, 'weekday' => 'none', 'which' => 'none', 'day' => 0, 'month' => 0, 'year' => 0, 'iso' => ''],
        'category' => '', 'merchant' => '', 'account' => '', 'to_account' => '', 'counterparty' => '', 'participants' => [],
        'due_date' => ['kind' => 'none', 'offset_days' => 0, 'weekday' => 'none', 'which' => 'none', 'day' => 0, 'month' => 0, 'year' => 0, 'iso' => ''],
        'interest_rate' => '', 'tenure_months' => 0, 'emi_amount' => '', 'action' => 'none', 'recurrence' => 'none', 'payment_method' => 'none',
        'description' => '', 'target_kind' => 'none', 'target_amount' => '', 'target_text' => '', 'query_metric' => 'total_spend',
        'period' => ['kind' => 'this_month', 'month' => 0, 'year' => 0, 'days' => 0, 'start' => '', 'end' => ''],
        'compare_period' => ['kind' => 'none', 'month' => 0, 'year' => 0, 'days' => 0, 'start' => '', 'end' => ''],
        'group_by' => 'none', 'limit' => 0, 'search_text' => '', 'export_format' => 'none', 'missing_fields' => [], 'clarification_question' => '', 'confidence' => 0.9,
    ];
}

it('turns every explicit empty value back into null', function () {
    $out = ResponseNormalizer::normalize(['language' => 'en', 'items' => [emptyItem()]])['items'][0];

    foreach (['event_type', 'amount', 'currency', 'category', 'merchant', 'account', 'to_account', 'counterparty', 'participants', 'due_date', 'interest_rate', 'tenure_months',
        'emi_amount', 'action', 'recurrence', 'payment_method', 'description', 'target_kind', 'target_amount', 'target_text', 'compare_period', 'group_by', 'limit', 'search_text',
        'export_format', 'clarification_question'] as $field) {
        expect($out[$field])->toBeNull("{$field} should be null");
    }
    expect($out['query_metric'])->toBe('total_spend')->and($out['period'])->toBe(['kind' => 'this_month', 'month' => null, 'year' => null, 'days' => null, 'start' => null, 'end' => null])
        ->and($out['date'])->toBe(['kind' => 'none', 'offset_days' => null, 'weekday' => null, 'which' => null, 'day' => null, 'month' => null, 'year' => null, 'iso' => null])
        ->and($out['confidence'])->toBe(0.9);
});

it('keeps real values untouched', function () {
    $item = emptyItem(['amount' => '250', 'category' => 'vegetables', 'event_type' => 'expense', 'limit' => 3, 'recurrence' => 'monthly',
        'date' => ['kind' => 'relative_days', 'offset_days' => 0, 'weekday' => 'none', 'which' => 'none', 'day' => 0, 'month' => 0, 'year' => 0, 'iso' => ''],
        'due_date' => ['kind' => 'weekday', 'offset_days' => 0, 'weekday' => 'fri', 'which' => 'next', 'day' => 0, 'month' => 0, 'year' => 0, 'iso' => ''],
        'period' => ['kind' => 'month', 'month' => 10, 'year' => 0, 'days' => 0, 'start' => '', 'end' => ''],
        'participants' => [['name' => 'Rahul', 'amount' => ''], ['name' => 'Amit', 'amount' => '800']]]);

    $out = ResponseNormalizer::normalize(['items' => [$item]])['items'][0];

    expect($out['amount'])->toBe('250')->and($out['event_type'])->toBe('expense')->and($out['limit'])->toBe(3)->and($out['recurrence'])->toBe('monthly')
        ->and($out['date']['offset_days'])->toBe(0)                       // "today" as relative_days 0 is a real value
        ->and($out['due_date']['weekday'])->toBe('fri')->and($out['due_date']['which'])->toBe('next')
        ->and($out['period']['month'])->toBe(10)->and($out['period']['year'])->toBeNull()
        ->and($out['participants'][0]['amount'])->toBeNull()->and($out['participants'][1]['amount'])->toBe('800');
});

it('is idempotent and passes answers that already use nulls (recorded eval fixtures) through unchanged', function () {
    $nulls = ['items' => [['intent' => 'record_event', 'event_type' => 'expense', 'amount' => '250', 'category' => null, 'period' => null, 'participants' => null, 'limit' => null, 'due_date' => null]]];

    expect(ResponseNormalizer::normalize($nulls))->toBe($nulls)->and(ResponseNormalizer::normalize(ResponseNormalizer::normalize(['items' => [emptyItem()]])))->toBe(ResponseNormalizer::normalize(['items' => [emptyItem()]]));
});

it('does not choke on malformed answers (the validator reports them)', function () {
    expect(ResponseNormalizer::normalize(['items' => 'nope']))->toBe(['items' => 'nope'])->and(ResponseNormalizer::normalize(['items' => ['x']]))->toBe(['items' => ['x']])
        ->and(ResponseNormalizer::normalize([]))->toBe([]);
});

it('end to end: an answer in the new empty-value format becomes a recorded expense', function () {
    useInterpretationHandler();
    FakeWhatsAppProvider::reset();
    FakeAIProvider::reset();
    $user = ledgerUser('919876543210');
    FakeAIProvider::respond(['language' => 'en', 'items' => [emptyItem([
        'intent' => 'record_event', 'event_type' => 'expense', 'amount' => '250', 'category' => 'vegetables', 'query_metric' => 'none',
        'period' => ['kind' => 'none', 'month' => 0, 'year' => 0, 'days' => 0, 'start' => '', 'end' => ''],
    ])]]);

    waText($this, 'spent 250 on vegetables');

    expect(LedgerTransaction::count())->toBe(1)->and(sentTexts()[0])->toContain('Recorded ₹250 expense under *Vegetables*');
});
