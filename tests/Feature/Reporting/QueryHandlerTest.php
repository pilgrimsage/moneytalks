<?php

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\LedgerTransaction;
use App\Services\AI\FakeAIProvider;
use App\Services\Reporting\ExportService;
use App\Services\Reporting\PeriodResolver;
use App\Services\WhatsApp\DTO\Outbound;
use App\Services\WhatsApp\MetaWhatsAppProvider;
use App\Services\WhatsApp\Testing\FakeWhatsAppProvider;
use Illuminate\Support\Facades\Http;

function qItem(array $o = []): array
{
    return aiItem($o + ['intent' => 'query', 'event_type' => null, 'amount' => null, 'category' => null, 'query_metric' => 'total_spend']);
}

function qPeriod(string $kind, array $o = []): array
{
    return ['kind' => $kind] + $o + ['month' => null, 'year' => null, 'days' => null, 'start' => null, 'end' => null];
}

beforeEach(function () {
    useInterpretationHandler();
    FakeWhatsAppProvider::reset();
    FakeAIProvider::reset();
    config(['whatsapp.outbound.retry_backoff_ms' => [0, 0]]);
    $this->user = ledgerUser('919876543210');
    $this->from = '919876543210';
    $this->ask = fn (string $text, ?string $id = null) => postWebhook($this, waFactory()->text($this->from, $text, $id))->assertOk();
    $post = fn (string $amount, string $category, string $date = '2026-10-03', TransactionType $type = TransactionType::Expense, array $o = []) => ledger()->post(command(
        $this->user, $type, $amount,
        $o + ['occurredOn' => $date, 'categoryId' => category($this->user, $category)->id],
    ));
    $this->post = $post;
});

describe('answering questions from the ledger', function () {
    it('answers "how much did I spend" with the exact sum and never records anything', function () {
        ($this->post)('250', 'Vegetables', '2026-10-02');
        ($this->post)('1000.50', 'Fuel', '2026-10-03');
        ($this->post)('999', 'Fuel', '2026-09-30'); // last month: excluded
        $before = LedgerTransaction::count();
        FakeAIProvider::respond(aiEnvelope(qItem(['period' => qPeriod('this_month')])));

        ($this->ask)('how much did I spend this month?');

        expect(sentTexts()[0])->toContain('₹1,250.5')->and(sentTexts()[0])->toContain('2 transactions')
            ->and(LedgerTransaction::count())->toBe($before);
    });

    it('filters by category including its sub-categories', function () {
        ($this->post)('250', 'Vegetables');
        ($this->post)('500', 'Fuel');
        FakeAIProvider::respond(aiEnvelope(qItem(['category' => 'food', 'period' => qPeriod('this_month')])));

        ($this->ask)('food spending this month');

        expect(sentTexts()[0])->toContain('₹250')->and(sentTexts()[0])->not->toContain('₹750');
    });

    it('does not guess when a category is unknown', function () {
        ($this->post)('250', 'Vegetables');
        FakeAIProvider::respond(aiEnvelope(qItem(['category' => 'spaceships', 'period' => qPeriod('this_month')])));

        ($this->ask)('spaceship spending');

        expect(sentTexts()[0])->toContain('don\'t have a category called "spaceships"');
    });

    it('gives a monthly summary with savings and top categories', function () {
        ($this->post)('45000', 'Salary', '2026-10-01', TransactionType::Income);
        ($this->post)('9000', 'Vegetables');
        ($this->post)('1000', 'Fuel');
        FakeAIProvider::respond(aiEnvelope(qItem(['intent' => 'report', 'query_metric' => 'summary', 'period' => qPeriod('this_month')])));

        ($this->ask)('monthly report');

        $t = sentTexts()[0];
        expect($t)->toContain('Income: ₹45,000')->and($t)->toContain('Expenses: ₹10,000')->and($t)->toContain('Saved: ₹35,000 (77% of income)')
            ->and($t)->toContain('Food: ₹9,000 (90%)')->and($t)->toContain('Biggest expense: ₹9,000');
    });

    it('compares two months and warns that the current one is unfinished', function () {
        ($this->post)('1000', 'Fuel', '2026-09-15');
        ($this->post)('1500', 'Fuel', '2026-10-02');
        FakeAIProvider::respond(aiEnvelope(qItem(['query_metric' => 'compare', 'period' => qPeriod('this_month'), 'compare_period' => qPeriod('last_month')])));

        ($this->ask)('compare this month with last month');

        expect(sentTexts()[0])->toContain('₹500 more')->and(sentTexts()[0])->toContain('(50%)')->and(sentTexts()[0])->toContain("isn't over yet");
    });

    it('says plainly when there is nothing to report', function () {
        FakeAIProvider::respond(aiEnvelope(qItem(['period' => qPeriod('last_year')])));

        ($this->ask)('spend last year');

        expect(sentTexts()[0])->toContain('No transactions in');
    });

    it('does not count undone transactions', function () {
        $tx = ($this->post)('700', 'Fuel');
        ($this->post)('300', 'Fuel');
        ledger()->reverse($this->user->id, $tx->transaction->id ?? $tx->id, 'test', TransactionSource::Manual, null);
        FakeAIProvider::respond(aiEnvelope(qItem(['period' => qPeriod('this_month')])));

        ($this->ask)('spend this month');

        expect(sentTexts()[0])->toContain('₹300')->and(sentTexts()[0])->toContain('1 transaction');
    });

    it('lists the biggest expenses', function () {
        ($this->post)('100', 'Fuel');
        ($this->post)('900', 'Vegetables');
        FakeAIProvider::respond(aiEnvelope(qItem(['query_metric' => 'biggest_expenses', 'limit' => 1, 'period' => qPeriod('this_month')])));

        ($this->ask)('biggest expense');

        expect(sentTexts()[0])->toContain('1. 3 Oct · ₹900 · Vegetables')->and(sentTexts()[0])->not->toContain('₹100');
    });

    it('answers honestly about features that do not exist yet', function () {
        FakeAIProvider::respond(aiEnvelope(qItem(['query_metric' => 'affordability'])));

        ($this->ask)('can I spend 10k');

        expect(sentTexts()[0])->toContain('can\'t answer questions about affordability questions yet');
    });

    it('never shows another user\'s numbers', function () {
        $other = ledgerUser('919000000001');
        ledger()->post(command($other, TransactionType::Expense, '5000', ['occurredOn' => '2026-10-03', 'categoryId' => category($other, 'Fuel')->id]));
        ($this->post)('100', 'Fuel');
        FakeAIProvider::respond(aiEnvelope(qItem(['period' => qPeriod('this_month')])));

        ($this->ask)('spend this month');

        expect(sentTexts()[0])->toContain('₹100')->and(sentTexts()[0])->not->toContain('5,000');
    });

    it('asks what is wanted when the metric is missing', function () {
        FakeAIProvider::respond(aiEnvelope(qItem(['query_metric' => null])));

        ($this->ask)('hmm money');

        expect(sentTexts()[0])->toContain('What would you like to know?');
    });
});

describe('CSV export', function () {
    it('sends a CSV document with a BOM and the right rows, then deletes the temp file', function () {
        ($this->post)('250', 'Vegetables', '2026-10-02', TransactionType::Expense, ['description' => 'sabji']);
        ($this->post)('99', 'Fuel', '2026-09-02');
        FakeAIProvider::respond(aiEnvelope(aiItem(['intent' => 'export', 'event_type' => null, 'amount' => null, 'category' => null, 'export_format' => 'csv', 'period' => qPeriod('this_month')])));

        ($this->ask)('export this month');

        $doc = FakeWhatsAppProvider::$documents[0];
        expect(str_starts_with($doc['contents'], "\xEF\xBB\xBF"))->toBeTrue()
            ->and($doc['filename'])->toEndWith('.csv')
            ->and($doc['contents'])->toContain('date,type,amount,currency,category,account,to_account,merchant,description,payment_method,status,id')
            ->and($doc['contents'])->toContain('2026-10-02,expense,250.00,INR,Vegetables,Cash,,,sabji')
            ->and($doc['contents'])->not->toContain('2026-09-02');
        expect(glob(storage_path('app/private/exports/*.csv')))->toBe([]);
    });

    it('declines formats it cannot make, and empty exports', function () {
        FakeAIProvider::respond(aiEnvelope(aiItem(['intent' => 'export', 'event_type' => null, 'amount' => null, 'category' => null, 'export_format' => 'pdf', 'period' => qPeriod('this_month')])));
        ($this->ask)('pdf please');
        FakeAIProvider::respond(aiEnvelope(aiItem(['intent' => 'export', 'event_type' => null, 'amount' => null, 'category' => null, 'export_format' => 'csv', 'period' => qPeriod('this_month')])));
        ($this->ask)('export');

        expect(sentTexts()[0])->toContain('only export CSV')->and(sentTexts()[1])->toContain('Nothing to export')->and(FakeWhatsAppProvider::$documents)->toBe([]);
    });

    it('neutralises spreadsheet formulas in text cells', function () {
        $e = app(ExportService::class);

        expect($e->safe('=HYPERLINK("x")'))->toBe("'=HYPERLINK(\"x\")")->and($e->safe('+1'))->toBe("'+1")->and($e->safe('-2'))->toBe("'-2")
            ->and($e->safe('@sum'))->toBe("'@sum")->and($e->safe('Groceries'))->toBe('Groceries')->and($e->safe(''))->toBe('');
    });

    it('also neutralises formulas hidden behind leading spaces or a non-breaking space', function () {
        $e = app(ExportService::class);

        expect($e->safe(' =1+1'))->toBe("' =1+1")->and($e->safe("\u{A0}@SUM(A1)"))->toBe("'\u{A0}@SUM(A1)")->and($e->safe('Total - tax'))->toBe('Total - tax');
    });

    it('creates the export file private from the first byte', function () {
        ($this->post)('250', 'Vegetables', '2026-10-02');
        $file = app(ExportService::class)->csv($this->user, app(PeriodResolver::class)->resolve(['kind' => 'this_month'], 'Asia/Kolkata'));

        expect(substr(sprintf('%o', fileperms($file['path'])), -4))->toBe('0600');
        @unlink($file['path']);
    });

    it('only exports the asking user\'s own transactions', function () {
        $other = ledgerUser('919000000001');
        ledger()->post(command($other, TransactionType::Expense, '5000', ['occurredOn' => '2026-10-03', 'categoryId' => category($other, 'Fuel')->id, 'description' => 'SECRET']));
        ($this->post)('100', 'Fuel');
        FakeAIProvider::respond(aiEnvelope(aiItem(['intent' => 'export', 'event_type' => null, 'amount' => null, 'category' => null, 'export_format' => 'csv', 'period' => qPeriod('this_month')])));

        ($this->ask)('export');

        expect(FakeWhatsAppProvider::$documents[0]['contents'])->not->toContain('SECRET');
    });
});

describe('Meta document sending', function () {
    it('uploads the file, then sends it as a document by media id', function () {
        config(['whatsapp.provider' => 'meta', 'whatsapp.meta.access_token' => 'tok', 'whatsapp.meta.phone_number_id' => '123']);
        Http::fake([
            '*/123/media' => Http::response(['id' => 'MEDIA1']),
            '*/123/messages' => Http::response(['messages' => [['id' => 'wamid.DOC']]]),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'mt');
        file_put_contents($path, "a,b\n1,2\n");

        $result = (new MetaWhatsAppProvider)->send(Outbound::document('919876543210', $path, 'x.csv', 'caption'));
        @unlink($path);

        expect($result->waMessageId)->toBe('wamid.DOC');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/123/media') && $r->hasHeader('Authorization'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/123/messages') && $r['type'] === 'document' && $r['document']['id'] === 'MEDIA1' && $r['document']['filename'] === 'x.csv');
    });
});
