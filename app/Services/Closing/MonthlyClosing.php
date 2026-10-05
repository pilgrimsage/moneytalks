<?php

namespace App\Services\Closing;

use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Reporting\PeriodResolver;
use App\Services\Reporting\ReportQuery;
use App\Services\Reporting\ReportRunner;
use App\Services\WhatsApp\OutboundMessageService;
use Carbon\CarbonImmutable;

/**
 * The monthly closing: in the first days of a month, each user gets last month's summary (the same deterministic
 * report as "monthly report"). Keyed per user and month, so it is sent at most once; if the 24-hour window is closed the
 * failure is recorded and retried on later runs during the first week, then given up. Months with no activity send nothing.
 */
class MonthlyClosing
{
    private const FIRST_DAYS = 7;

    private const FROM_HOUR = 9;

    public function __construct(
        private readonly ReportRunner $reports,
        private readonly PeriodResolver $periods,
        private readonly OutboundMessageService $out,
    ) {}

    /** @return int summaries sent */
    public function run(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $sent = 0;

        foreach (User::query()->where('status', 'active')->get() as $user) {
            $local = $now->setTimezone($user->timezone);
            if ($local->day > self::FIRST_DAYS || $local->hour < self::FROM_HOUR) {
                continue;
            }
            $period = $this->periods->resolve(['kind' => 'last_month'], $user->timezone, $now);
            $key = 'monthly:'.$user->id.':'.$period->start->format('Y-m');
            if (WhatsappMessage::where('dedupe_key', $key)->whereIn('status', ['sent', 'delivered', 'read'])->exists()) {
                continue; // this month's wrap-up already went out
            }
            $text = $this->reports->run($user, new ReportQuery('summary', $period));
            if (str_starts_with($text, 'No transactions')) {
                continue; // nothing happened last month: stay quiet
            }

            $row = $this->out->sendText($user, "📅 *Your {$period->start->format('F')} wrap-up*\n\n".preg_replace('/^📊 \*[^\n]*\*\n\n/u', '', $text), $key);
            $sent += $row->status->value === 'failed' ? 0 : 1;
        }

        return $sent;
    }
}
