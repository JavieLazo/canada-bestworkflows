<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Support\HrisFormat;

/**
 * The words on the payroll grid for what a draft charges to leave credits
 * (DIVERGENCE 15): "Why this amount?" for one member's Leave Charged
 * figure, one step per charged day — what the day was, what it converted
 * to, which leave types covered it and what was left for pay — and the
 * plan's notes. Presentation only; the plan comes from the calculation.
 */
final class LeaveChargeText
{
    private function __construct() {}

    /**
     * @param  array{lines: list<array{date: string, kind: string, minutes: ?int, days: float, covered: array<string, float>, uncovered_days: float, fraction: float}>, balances: array<string, float>, notes: list<string>}|null  $plan
     * @param  array<string, string>  $names  leave type token => name
     * @return array{title: string, amount: float, unit: string, steps: list<array{label: string, detail: string, state: string}>}
     */
    public static function explain(string $employeeName, float $days, ?array $plan, array $names): array
    {
        $steps = [];
        foreach ($plan['lines'] ?? [] as $line) {
            $covered = [];
            foreach ($line['covered'] as $token => $taken) {
                $covered[] = ($names[$token] ?? $token).' '.self::days($taken);
            }
            $what = $line['kind'] === 'LATE'
                ? 'Lateness and undertime of '.$line['minutes'].' min, '.self::days($line['days']).' by the conversion table'
                : 'Absent: '.self::days($line['days']);
            $steps[] = [
                'label' => HrisFormat::date($line['date']).' · '.($line['kind'] === 'LATE' ? 'lateness and undertime' : 'absence'),
                'detail' => $what.'. '.($covered === [] ? 'No leave credits covered it' : 'Deducted from '.implode(', ', $covered))
                    .($line['uncovered_days'] > 0 ? '; '.self::days($line['uncovered_days']).' left for pay.' : '.'),
                'state' => $covered === [] ? 'unused' : 'used',
            ];
        }
        foreach ($plan['notes'] ?? [] as $note) {
            $steps[] = ['label' => 'Not deducted', 'detail' => $note, 'state' => 'unset'];
        }
        if ($steps === []) {
            $steps[] = ['label' => 'Nothing to deduct', 'detail' => 'No lateness, undertime or absence on this payroll, or the leave settings deduct them from pay.', 'state' => 'unused'];
        }

        return ['title' => 'Leave Credits Deducted · '.$employeeName, 'amount' => $days, 'unit' => 'credits', 'steps' => $steps];
    }

    /** "1.25 credits", "1 credit": credits read at up to four decimals, never the grid's one-decimal day count. */
    public static function days(float $days): string
    {
        return self::credits($days).' '.(abs($days - 1.0) < 0.00005 ? 'credit' : 'credits');
    }

    /** A credit quantity at up to four decimals, trailing zeros trimmed: "2.125", "1", "0.0625". */
    public static function credits(float $days): string
    {
        $text = rtrim(rtrim(number_format($days, 4, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }
}
