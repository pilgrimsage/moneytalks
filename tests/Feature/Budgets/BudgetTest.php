<?php

use App\Enums\TransactionType;
use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Services\AI\FakeAIProvider;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;

function bItem(array $o = []): array
{
    return aiItem($o + ['intent' => 'create_budget', 'event_type' => null, 'amount' => '5000', 'category' => 'food', 'action' => 'set']);
}

beforeEach(function () {
    useInterpretationHandler();
    FakeWhatsAppProvider::reset();
    FakeAIProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    $this->user = ledgerUser('919876543210');
    $this->say = function (string $text, array $item) {
        FakeAIProvider::respond(aiEnvelope($item));
        waText($this, $text);
    };
    $this->spend = fn (string $amount, string $category) => ledger()->post(command($this->user, TransactionType::Expense, $amount, [
        'occurredOn' => now($this->user->timezone)->format('Y-m-d'), 'categoryId' => category($this->user, $category)->id,
    ]));
});

describe('setting budgets', function () {
    it('sets a category budget and reports progress so far', function () {
        ($this->spend)('1000', 'Vegetables');

        ($this->say)('set a budget of 5000 for food', bItem());

        expect(sentTexts()[0])->toContain('Food budget set to ₹5,000 per month')->and(sentTexts()[0])->toContain('₹1,000 (20%)')
            ->and(Budget::count())->toBe(1);
    });

    it('updates instead of duplicating, supports an overall budget, and can remove one', function () {
        ($this->say)('budget 5000 food', bItem());
        ($this->say)('budget 6000 food', bItem(['amount' => '6000']));
        ($this->say)('monthly budget 40000', bItem(['amount' => '40000', 'category' => null]));
        ($this->say)('remove food budget', bItem(['amount' => null, 'action' => 'remove']));

        expect(Budget::where('status', 'active')->count())->toBe(1)->and(Budget::where('status', 'active')->first()->category_id)->toBeNull()
            ->and(Budget::where('category_id', category($this->user, 'Food')->id)->first()->amount_minor)->toBe(600000)
            ->and(sentTexts()[3])->toContain('Removed the Food budget');
    });

    it('does not guess an unknown category or an amount that is not in the message', function () {
        ($this->say)('budget 5000 spaceships', bItem(['category' => 'spaceships']));
        ($this->say)('budget 5000 food', bItem(['amount' => '9999']));

        expect(Budget::count())->toBe(0)->and(sentTexts()[0])->toContain("don't have an expense category")->and(sentTexts()[1])->toContain("can't find that amount");
    });
});

describe('budget alerts', function () {
    beforeEach(function () {
        ($this->say)('budget 1000 for food', bItem(['amount' => '1000']));
    });

    it('warns once at 80% and once at 100%, counting sub-categories, and never repeats', function () {
        ($this->spend)('790', 'Vegetables'); // Vegetables is under Food
        ($this->say)('spent 50 on vegetables', aiItem(['amount' => '50', 'category' => 'vegetables']));
        expect(sentTexts()[1])->toContain('⚠️ Food budget: ₹840 of ₹1,000 used (84%)');

        ($this->say)('spent 20 on vegetables', aiItem(['amount' => '20', 'category' => 'vegetables']));
        expect(sentTexts()[2])->not->toContain('budget');

        ($this->say)('spent 300 on vegetables', aiItem(['amount' => '300', 'category' => 'vegetables']));
        expect(sentTexts()[3])->toContain('🚨 Food budget exceeded: ₹1,160 of ₹1,000');

        ($this->say)('spent 10 on vegetables', aiItem(['amount' => '10', 'category' => 'vegetables']));
        expect(sentTexts()[4])->not->toContain('budget')->and(BudgetAlert::count())->toBe(2);
    });

    it('stays quiet for other categories and for backdated entries', function () {
        ($this->say)('spent 900 on petrol', aiItem(['amount' => '900', 'category' => 'petrol']));
        ($this->say)('spent 900 on vegetables yesterday', aiItem(['amount' => '900', 'category' => 'vegetables', 'date' => aiDate('relative_days', ['offset_days' => -35])]));

        expect(sentTexts()[1])->not->toContain('budget')->and(sentTexts()[2])->not->toContain('budget');
    });

    it('also alerts when a confirmed entry crosses the line', function () {
        ($this->say)('spent 150000 on vegetables', aiItem(['amount' => '150000', 'category' => 'vegetables']));
        waTap($this, lastButtons()['confirm']);

        expect(end(FakeWhatsAppProvider::$sent)->body)->toContain('Food budget exceeded');
    });
});

describe('budget status', function () {
    it('lists budgets with progress, and explains when there are none', function () {
        ($this->say)('how are my budgets?', aiItem(['intent' => 'query', 'event_type' => null, 'amount' => null, 'category' => null, 'query_metric' => 'budget_status']));
        expect(sentTexts()[0])->toContain('no budgets yet');

        ($this->say)('budget 1000 food', bItem(['amount' => '1000']));
        ($this->spend)('850', 'Groceries');
        ($this->say)('how are my budgets?', aiItem(['intent' => 'query', 'event_type' => null, 'amount' => null, 'category' => null, 'query_metric' => 'budget_status']));

        expect(end(FakeWhatsAppProvider::$sent)->body)->toContain('⚠️ Food: ₹850 of ₹1,000 (85%)');
    });
});
