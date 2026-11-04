<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\Recurring\RecurringLine;
use App\Support\Money;

/**
 * When a loan is paid off, for the line dialog's preview: how many payrolls
 * it takes and the first day of the last one, if every payroll the line is
 * due on pays what the line proposes from now on and none is skipped. Read
 * off RecurringLine's own rules (dueOn, proposed, paidOff), payroll for
 * payroll, so the preview and the payrolls cannot disagree. It moves no
 * peso: payrolls still propose and post as they always do.
 */
final class RecurringLinePayoff
{
    /** How many cut-offs it looks ahead before it gives up: twenty years of payrolls. */
    public const int HORIZON = 480;

    /**
     * Null for a line with no total, one already paid off, one its last date
     * stops first, or one the horizon does not reach. Cut-offs start on the
     * 1st and the 16th; counting starts at the line's first cut-off or at
     * `$from` (the next cut-off, for a line already running), whichever is
     * later.
     *
     * @return array{payrolls: int, last: string}|null
     */
    public static function of(RecurringLine $line, float $paid = 0.0, ?string $from = null): ?array
    {
        if ($line->total === null || $line->paidOff($paid)) {
            return null;
        }
        $begin = $from !== null && $from > $line->startsOn ? $from : $line->startsOn;
        $first = (int) substr($begin, 0, 4) * 24 + ((int) substr($begin, 5, 2) - 1) * 2 + ((int) substr($begin, 8, 2) > 15 ? 1 : 0);
        $payrolls = 0;
        for ($step = 0; $step < self::HORIZON; $step++) {
            $index = $first + $step;
            $day = sprintf('%04d-%02d-%s', intdiv($index, 24), intdiv($index % 24, 2) + 1, $index % 2 === 0 ? '01' : '16');
            if ($line->endsOn !== null && $day > $line->endsOn) {
                return null;
            }
            if ($day < $line->startsOn || ! $line->dueOn($day)) {
                continue;
            }
            $paid = Money::round($paid + $line->proposed($paid));
            $payrolls++;
            if ($line->paidOff($paid)) {
                return ['payrolls' => $payrolls, 'last' => $day];
            }
        }

        return null;
    }
}
