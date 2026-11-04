<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

/**
 * One employee's leave policy (or a preset it is copied from): the pure
 * shape every rule of the leave-credits flow reads, validated strictly.
 *
 *   posting_day    MONTH_END, or a day of the month (1–28) the earning posts on
 *   unpaid_counts  what counts as a day without pay when a month is earned:
 *                  UNPAID_LEAVE (approved leave without pay), ABSENCE (a
 *                  scheduled day not worked and not on leave, whether or not
 *                  a payroll charged it to credits)
 *   earning        per leave type token that earns: the monthly amount, the
 *                  method (FLAT: the amount; PRO_RATA: the amount × days
 *                  present ÷ days scheduled; TABLE: a lookup of days without
 *                  pay to credits), the partial-month method for a month the
 *                  employee did not serve whole (FULL, PRO_RATA, NONE) and an
 *                  optional balance cap. There is no year-end rule: credits
 *                  never lapse, the whole balance carries (LeaveEarningRule)
 *   charges        whether attendance is charged to credits on each payroll,
 *                  how minutes combine (DAY, PAYROLL) and the ordered leave
 *                  types for lateness/undertime and for absences
 *   cash_out_max_per_year  the most days the employee may cash out in a
 *                  calendar year (LeaveCashOutPolicy), or null for no limit
 *
 * Nothing here reads a clock, a database or a screen. The amounts are
 * HR's: the shipped shape holds no figure of its own.
 */
final class LeavePolicy
{
    public const string MONTH_END = 'MONTH_END';

    public const array UNPAID_COUNTS = ['UNPAID_LEAVE', 'ABSENCE'];

    /** @var array<string, string> */
    public const array UNPAID_LABELS = ['UNPAID_LEAVE' => 'Approved leave without pay', 'ABSENCE' => 'Absences, charged to credits or not'];

    public const array METHODS = ['FLAT', 'PRO_RATA', 'TABLE'];

    /** @var array<string, string> */
    public const array METHOD_LABELS = ['FLAT' => 'Flat amount every month', 'PRO_RATA' => 'Pro-rata to days present', 'TABLE' => 'Lookup table by days without pay'];

    public const array PARTIALS = ['FULL', 'PRO_RATA', 'NONE'];

    /** @var array<string, string> */
    public const array PARTIAL_LABELS = ['FULL' => 'The whole amount', 'PRO_RATA' => 'Pro-rata to the days served', 'NONE' => 'Nothing that month'];

    public const array COMBINES = ['DAY', 'PAYROLL'];

    /** @var array<string, string> */
    public const array COMBINE_LABELS = ['DAY' => 'Convert each day on its own', 'PAYROLL' => 'Add the payroll\'s minutes up, then convert'];

    public const int MAX_TABLE_ROWS = 40;

    /** @param  array<string, mixed>  $params */
    private function __construct(public readonly array $params) {}

    /** Today's behaviour for everyone: nothing earns, nothing is charged. */
    public static function none(): self
    {
        return new self(['posting_day' => self::MONTH_END, 'unpaid_counts' => [], 'earning' => [], 'charges' => ['enabled' => false, 'combine' => 'DAY', 'late' => [], 'absence' => []], 'cash_out_max_per_year' => null]);
    }

    /**
     * Why typed params are refused, or null when they are acceptable.
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $earningTokens  the leave types that may earn (Leave Credits settings)
     * @param  list<string>  $lateTokens  the leave types lateness may be charged to, in order
     * @param  list<string>  $absenceTokens  the leave types absences may be charged to, in order
     */
    public static function error(array $params, array $earningTokens, array $lateTokens, array $absenceTokens): ?string
    {
        $posting = $params['posting_day'] ?? null;
        if ($posting !== self::MONTH_END && ! (is_int($posting) && $posting >= 1 && $posting <= 28) && ! (is_string($posting) && preg_match('/\A([1-9]|1\d|2[0-8])\z/', $posting) === 1)) {
            return 'Choose the day of the month the earning posts on: the month end, or a day from 1 to 28.';
        }
        $counts = $params['unpaid_counts'] ?? null;
        if (! is_array($counts) || ! array_is_list($counts) || array_diff($counts, self::UNPAID_COUNTS) !== []) {
            return 'Choose what counts as a day without pay.';
        }
        $earning = $params['earning'] ?? null;
        if (! is_array($earning)) {
            return 'The earning rules are missing.';
        }
        foreach ($earning as $token => $rule) {
            if (! is_string($token) || ! in_array($token, $earningTokens, true)) {
                return 'A leave type in the earning rules cannot earn credits. Set its credit rules on Leave Credits first.';
            }
            if (! is_array($rule)) {
                return 'The earning rule is incomplete.';
            }
            $amountError = LeaveQuantity::error($rule['amount'] ?? '');
            if ($amountError !== null || trim((string) ($rule['amount'] ?? '')) === '') {
                return 'Enter the monthly amount earned.'.($amountError === null ? '' : ' '.$amountError);
            }
            if (! in_array($rule['method'] ?? null, self::METHODS, true)) {
                return 'Choose how the month is earned.';
            }
            if (! in_array($rule['partial'] ?? null, self::PARTIALS, true)) {
                return 'Choose what a part month earns.';
            }
            if (($rule['cap'] ?? null) !== null && (trim((string) $rule['cap']) === '' || LeaveQuantity::error($rule['cap']) !== null)) {
                return 'Balance cap: '.(LeaveQuantity::error($rule['cap']) ?? LeaveQuantity::FORMAT_ERROR);
            }
            if ($rule['method'] === 'TABLE') {
                $table = $rule['table'] ?? null;
                if (! is_array($table) || ! array_is_list($table) || $table === [] || count($table) > self::MAX_TABLE_ROWS) {
                    return 'The lookup table needs 1 to '.self::MAX_TABLE_ROWS.' rows.';
                }
                $previous = -1.0;
                foreach ($table as $row) {
                    if (! is_array($row) || LeaveQuantity::error($row['up_to'] ?? '') !== null || trim((string) ($row['up_to'] ?? '')) === ''
                        || LeaveQuantity::error($row['credits'] ?? '') !== null || trim((string) ($row['credits'] ?? '')) === '') {
                        return 'Each lookup row needs the days without pay it reaches up to, and the credits earned.';
                    }
                    if ((float) $row['up_to'] <= $previous) {
                        return 'Lookup rows must rise in days without pay.';
                    }
                    $previous = (float) $row['up_to'];
                }
            }
        }
        $charges = $params['charges'] ?? null;
        if (! is_array($charges) || ! is_bool($charges['enabled'] ?? null)) {
            return 'Choose whether attendance is charged to credits.';
        }
        if (! in_array($charges['combine'] ?? null, self::COMBINES, true)) {
            return 'Choose how late and undertime minutes combine.';
        }
        foreach (['late' => $lateTokens, 'absence' => $absenceTokens] as $kind => $allowed) {
            $list = $charges[$kind] ?? null;
            if (! is_array($list) || ! array_is_list($list) || array_diff($list, $allowed) !== [] || count($list) !== count(array_unique($list))) {
                return 'A leave type in the charge order cannot be charged for '.($kind === 'late' ? 'lateness' : 'absences').'. Set its credit rules on Leave Credits first.';
            }
        }
        if ($charges['enabled'] && $charges['late'] === [] && $charges['absence'] === []) {
            return 'Charging to credits needs at least one leave type to charge.';
        }
        $cashOutMax = $params['cash_out_max_per_year'] ?? null;
        if ($cashOutMax !== null && trim((string) $cashOutMax) !== '') {
            $maxError = LeaveQuantity::error($cashOutMax);
            if ($maxError !== null) {
                return 'Cash-out limit per year: '.$maxError;
            }
            if ((LeaveQuantity::parse($cashOutMax) ?? 0.0) <= 0.0) {
                return 'Cash-out limit per year: enter more than zero, or leave it blank for no limit.';
            }
        }

        return null;
    }

    /**
     * The params as stored, once error() has passed: numbers settled,
     * tokens in the given orders, nothing else.
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $lateTokens
     * @param  list<string>  $absenceTokens
     */
    public static function fromParams(array $params, array $lateTokens = [], array $absenceTokens = []): self
    {
        $earning = [];
        foreach ($params['earning'] ?? [] as $token => $rule) {
            $table = [];
            if (($rule['method'] ?? '') === 'TABLE') {
                foreach ($rule['table'] ?? [] as $row) {
                    $table[] = ['up_to' => LeaveQuantity::parse($row['up_to']) ?? 0.0, 'credits' => LeaveQuantity::parse($row['credits']) ?? 0.0];
                }
            }
            $earning[(string) $token] = [
                'amount' => LeaveQuantity::parse($rule['amount']) ?? 0.0,
                'method' => (string) $rule['method'],
                'table' => $table,
                'partial' => (string) $rule['partial'],
                'cap' => ($rule['cap'] ?? null) === null || trim((string) $rule['cap']) === '' ? null : LeaveQuantity::parse($rule['cap']),
            ];
        }
        ksort($earning, SORT_STRING);
        $charges = $params['charges'] ?? [];
        $posting = $params['posting_day'] ?? self::MONTH_END;
        $counts = array_values(array_intersect(self::UNPAID_COUNTS, $params['unpaid_counts'] ?? []));
        $cashOutMax = $params['cash_out_max_per_year'] ?? null;

        return new self([
            'posting_day' => $posting === self::MONTH_END ? self::MONTH_END : (int) $posting,
            'unpaid_counts' => $counts,
            'earning' => $earning,
            'charges' => [
                'enabled' => (bool) ($charges['enabled'] ?? false),
                'combine' => (string) ($charges['combine'] ?? 'DAY'),
                'late' => $lateTokens === [] ? array_values($charges['late'] ?? []) : array_values(array_intersect($lateTokens, $charges['late'] ?? [])),
                'absence' => $absenceTokens === [] ? array_values($charges['absence'] ?? []) : array_values(array_intersect($absenceTokens, $charges['absence'] ?? [])),
            ],
            'cash_out_max_per_year' => $cashOutMax === null || trim((string) $cashOutMax) === '' ? null : LeaveQuantity::parse($cashOutMax),
        ]);
    }

    /** The most days that may be cashed out in a calendar year; null for no limit. */
    public function cashOutMaxPerYear(): ?float
    {
        $max = $this->params['cash_out_max_per_year'] ?? null;

        return $max === null ? null : (float) $max;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->params;
    }

    public function postingDay(): int|string
    {
        return $this->params['posting_day'];
    }

    /** @return list<string> */
    public function unpaidCounts(): array
    {
        return $this->params['unpaid_counts'];
    }

    /** @return array<string, array{amount: float, method: string, table: list<array{up_to: float, credits: float}>, partial: string, cap: ?float}> */
    public function earning(): array
    {
        return $this->params['earning'];
    }

    public function chargesEnabled(): bool
    {
        return (bool) $this->params['charges']['enabled'];
    }

    public function combine(): string
    {
        return (string) $this->params['charges']['combine'];
    }

    /** @return list<string> */
    public function chargeOrder(string $kind): array
    {
        return $this->params['charges'][$kind === 'late' ? 'late' : 'absence'];
    }

    /**
     * "VL 1.25 a month (lookup) · SL 1.25 a month · posts at month end ·
     * charges to credits: lateness VL; absences VL, SL" for the list and
     * the audit trail.
     *
     * @param  array<string, string>  $names  token => leave type name
     */
    public function describe(array $names): string
    {
        $parts = [];
        foreach ($this->earning() as $token => $rule) {
            $parts[] = ($names[$token] ?? $token).' '.LeaveQuantity::format($rule['amount']).' a month'.match ($rule['method']) {
                'PRO_RATA' => ' (pro-rata)', 'TABLE' => ' (lookup table)', default => '',
            };
        }
        $summary = $parts === [] ? 'earns nothing' : implode(' · ', $parts);
        $summary .= ' · posts '.($this->postingDay() === self::MONTH_END ? 'at month end' : 'on day '.$this->postingDay());
        if ($this->chargesEnabled()) {
            $kinds = [];
            foreach (['late' => 'lateness', 'absence' => 'absences'] as $kind => $label) {
                $order = $this->chargeOrder($kind);
                if ($order !== []) {
                    $kinds[] = $label.' '.implode(', ', array_map(static fn (string $t): string => $names[$t] ?? $t, $order));
                }
            }
            $summary .= ' · charges to credits: '.implode('; ', $kinds);
        } else {
            $summary .= ' · attendance charged to pay';
        }
        if ($this->cashOutMaxPerYear() !== null) {
            $summary .= ' · cash-outs up to '.LeaveQuantity::format($this->cashOutMaxPerYear()).' days a year';
        }

        return $summary;
    }
}
