<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

/**
 * What one leave type earns in one month under an employee's policy
 * (LeavePolicy::earning), from the month's facts: the scheduled working
 * days, the days present (worked, on paid leave, official business or a
 * paid holiday), the days without pay the policy counts, the days the
 * employee was employed in the month, and the balance before the posting
 * (for the cap).
 *
 * Methods: FLAT earns the amount; PRO_RATA earns the amount × present ÷
 * scheduled; TABLE looks the days without pay up in HR's rows (the first
 * row whose `up_to` the count does not exceed; past the last row,
 * nothing). A month the employee did not serve whole takes the partial
 * rule: the whole amount, pro-rata to the scheduled days they were
 * employed on, or nothing. A cap stops the balance there. Every figure
 * settles to four decimals (LeaveQuantity). Pure.
 */
final class LeaveEarningRule
{
    private function __construct() {}

    /**
     * @param  array{amount: float, method: string, table: list<array{up_to: float, credits: float}>, partial: string, cap: ?float}  $rule
     * @return array{days: float, basis: string} the credits earned and a sentence of how
     */
    public static function month(array $rule, MonthFacts $facts): array
    {
        if ($facts->scheduledDays <= 0) {
            return ['days' => 0.0, 'basis' => 'no scheduled working day in the month'];
        }
        $whole = $facts->employedScheduledDays >= $facts->scheduledDays;
        if (! $whole && $rule['partial'] === 'NONE') {
            return ['days' => 0.0, 'basis' => 'a partial month accrues nothing'];
        }
        $amount = $rule['amount'];
        $basis = match ($rule['method']) {
            'PRO_RATA' => LeaveQuantity::format($amount).' × '.LeaveQuantity::format($facts->presentDays).' present ÷ '.$facts->scheduledDays.' scheduled',
            'TABLE' => LeaveQuantity::format($facts->unpaidDays).' unpaid '.($facts->unpaidDays == 1.0 ? 'day' : 'days').', by the lookup table',
            default => LeaveQuantity::format($amount).' flat',
        };
        $earned = match ($rule['method']) {
            'PRO_RATA' => $amount * min($facts->presentDays, $facts->scheduledDays) / $facts->scheduledDays,
            'TABLE' => self::lookup($rule['table'], $facts->unpaidDays),
            default => $amount,
        };
        if (! $whole && $rule['partial'] === 'PRO_RATA') {
            $earned = $earned * $facts->employedScheduledDays / $facts->scheduledDays;
            $basis .= ', pro-rata to '.$facts->employedScheduledDays.' of '.$facts->scheduledDays.' scheduled days employed';
        }
        $earned = LeaveQuantity::normalize(max(0.0, $earned));
        if ($rule['cap'] !== null && $facts->balanceBefore + $earned > $rule['cap']) {
            $earned = LeaveQuantity::normalize(max(0.0, $rule['cap'] - $facts->balanceBefore));
            $basis .= ', capped at a balance of '.LeaveQuantity::format($rule['cap']);
        }

        return ['days' => $earned, 'basis' => $basis];
    }

    /**
     * The credits of the first row whose `up_to` the days without pay do
     * not exceed; nothing past the last row.
     *
     * @param  list<array{up_to: float, credits: float}>  $table
     */
    public static function lookup(array $table, float $unpaidDays): float
    {
        foreach ($table as $row) {
            if ($unpaidDays <= $row['up_to']) {
                return $row['credits'];
            }
        }

        return 0.0;
    }

    /**
     * What a type's closing balance carries into the new year: ALL OF IT
     * for a type that carries — an employee keeps using earlier years'
     * credits for as long as they want, or cashes them out — and, for a
     * type that LAPSES (a yearly grant), nothing above zero. Either way a
     * balance below zero (an attendance charge past the credits held)
     * carries as the debt it is, so a new year never forgives it.
     */
    public static function yearEnd(float $closing, bool $lapses = false): float
    {
        $closing = LeaveQuantity::normalize($closing);

        return $lapses && $closing > 0.0 ? 0.0 : $closing;
    }

    /** What a lapsing type drops at the year end: the balance above zero, or nothing. */
    public static function lapsed(float $closing, bool $lapses): float
    {
        $closing = LeaveQuantity::normalize($closing);

        return $lapses && $closing > 0.0 ? $closing : 0.0;
    }
}
