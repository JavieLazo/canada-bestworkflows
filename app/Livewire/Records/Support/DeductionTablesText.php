<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Payroll\Domain\Canada\DeductionRates;
use App\Support\Currency;
use App\Support\HrisFormat;

/**
 * The published deduction figures in the words and numbers the Contribution
 * & Tax Tables page shows. Presentation only: every figure is read from a
 * DeductionRates set, so the page can never state a rate payroll does not
 * use.
 */
final class DeductionTablesText
{
    private function __construct() {}

    /** A rate as a percentage with only the decimals it has: 0.0595 reads "5.95%", 0.205 "20.5%", 0.14 "14%". */
    public static function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 4, '.', ''), '0'), '.').'%';
    }

    /** A whole-dollar threshold: "$58,523". */
    public static function dollars(float $amount): string
    {
        return Currency::SYMBOL.number_format($amount, 0, '.', ',');
    }

    /** The year a set is for: its first day's year. */
    public static function year(DeductionRates $rates): string
    {
        return substr($rates->effectiveFrom, 0, 4);
    }

    /** "Jan 01, 2027": the day a set takes effect, as every date on screen reads. */
    public static function startsOn(DeductionRates $rates): string
    {
        return HrisFormat::date($rates->effectiveFrom);
    }

    /**
     * A bracket table's rows: the income range in words and its rate.
     *
     * @param  list<array{float, float, float}>  $brackets
     * @return list<array{range: string, rate: string}>
     */
    public static function brackets(array $brackets): array
    {
        $rows = [];
        foreach ($brackets as $i => [$from, $rate]) {
            $to = $brackets[$i + 1][0] ?? null;
            $rows[] = [
                'range' => $to === null ? 'Over '.self::dollars($from) : self::dollars($from).' to '.self::dollars($to),
                'rate' => self::percent($rate),
            ];
        }

        return $rows;
    }

    /**
     * "$60,044, $120,088, $186,157 and $265,203": where each bracket after the first starts.
     *
     * @param  list<array<int, float|int>>  $brackets
     */
    public static function thresholds(array $brackets): string
    {
        $starts = array_map(static fn (array $bracket): string => self::dollars((float) $bracket[0]), array_slice($brackets, 1));
        $last = array_pop($starts);

        return $starts === [] ? (string) $last : implode(', ', $starts).' and '.$last;
    }

    /** How often a schedule with that many pays a year pays, as a title. */
    public static function payFrequency(int $pays): string
    {
        return match (true) {
            $pays >= 52 => 'Every Week',
            $pays >= 26 => 'Every Two Weeks',
            $pays === 24 => 'Twice a Month',
            $pays === 12 => 'Once a Month',
            default => $pays.' Times a Year',
        };
    }

    /**
     * "5 tax brackets · 14% to 33%".
     *
     * @param  list<array<int, float|int>>  $brackets
     */
    public static function bracketSummary(array $brackets): string
    {
        $rates = array_map(static fn (array $bracket): float => (float) $bracket[1], $brackets);
        if ($rates === []) {
            return 'No tax brackets';
        }

        return count($brackets).' tax brackets · '.self::percent(min($rates)).' to '.self::percent(max($rates));
    }
}
