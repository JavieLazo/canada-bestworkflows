<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Attendance\ShiftResolver;
use App\Support\Money;
use InvalidArgumentException;

/**
 * The Pay Basis & Rates a payslip is priced under — ONE immutable value the
 * engine is handed, read strictly from a saved version's figures.
 *
 * DELIBERATE DIVERGENCE 13. The reference prices every day the same way:
 * pay built up from the days worked (an unworked holiday earns nothing),
 * the daily rate as the monthly rate over the month's scheduled working
 * days, overtime and night pay from three Formulas rows, holiday overtime
 * at the holiday's percent times 1.3, the night window fixed at 22:00–06:00
 * and no night pay on overtime hours. EMS keeps every one of those as a
 * setting the user may change:
 *
 *  - `basis`: DAILY (the reference: days worked and paid leave are paid) or
 *    MONTHLY (the salary covers every scheduled day; absences, late and
 *    undertime are what reduce it, and an unworked holiday on a scheduled
 *    day stays paid).
 *  - `dailyRateMethod`: the month's scheduled working days (the reference),
 *    a yearly factor (monthly × 12 ÷ n) or a fixed divisor (monthly ÷ n).
 *  - the overtime, night and rest-day rates and caps (`rates`, the
 *    reference's Formulas), the holiday overtime factor, the night window
 *    and whether overtime hours earn night pay.
 *  - a MATRIX by day type: the worked day's rate, the overtime hour's rate,
 *    the night hour's rate and what an unworked day earns, each as a
 *    percent. A null cell means "as the reference derives it from the
 *    figures above" — the holiday's own percent, rest × overtime, the night
 *    rate on the base hourly — so `fromFormulas()` (every cell null, basis
 *    DAILY, the month's working days, nothing on overtime) performs exactly
 *    the reference's float operations and the golden vectors hold.
 *
 * Percent cells: `worked` is a percent of the daily rate (for REST, of the
 * hourly rate per hour worked, as the reference prices a rest day);
 * `overtime` a percent of the base hourly rate per overtime hour; `night`
 * the day's hourly rate for a night hour as a percent of the base hourly
 * rate, so the night ADD-ON is `night − worked` (110 on an ordinary day is a
 * 10% add-on); `unworked` the percent of the daily rate an unworked
 * scheduled day of that type earns (DAILY basis only). Pure: no clock,
 * database or framework.
 *
 * DELIBERATE DIVERGENCE 16, three more figures a version may set, each
 * blank by default so the reference's operations stand:
 *
 *  - `hourly_rate.hours`: the hours the daily rate is divided by for the
 *    hourly rate. Blank = the shift's own paid hours (the reference); a
 *    figure (8) prices every hour of every shift from it.
 *  - `rounding.decimals`: the decimals each RATE STEP is rounded to before
 *    it is multiplied on — the daily rate, the hourly rate and a premium
 *    hourly rate — the way a payroll register's ROUND(monthly ÷ 22, 2) and
 *    ROUND(daily ÷ 8, 2) do (`Money::roundTo`). Blank = no rounding (the
 *    reference prices from the unrounded rates).
 *  - `overtime.seaport_rate`: the hourly rate, as a fraction of the base
 *    hourly, for an overtime day the Daily Time Record approved as SEAPORT
 *    overtime, whatever the day type; a seaport day is never capped.
 *    Blank = a seaport day is priced as the day type's overtime, still
 *    uncapped.
 */
final class PayRules
{
    /** The overtime category the Daily Time Record may put a day's approval under. */
    public const string OVERTIME_SEAPORT = 'SEAPORT';

    public const string BASIS_DAILY = 'DAILY';

    public const string BASIS_MONTHLY = 'MONTHLY';

    public const string METHOD_MONTH = 'MONTH_WORKING_DAYS';

    public const string METHOD_FACTOR = 'ANNUAL_FACTOR';

    public const string METHOD_DIVISOR = 'FIXED_DIVISOR';

    /** @var list<string> */
    public const array DAY_TYPES = ['ORDINARY', 'REST', 'SPECIAL', 'SPECIAL_REST', 'REGULAR', 'REGULAR_REST', 'DOUBLE', 'DOUBLE_REST'];

    /** @var list<string> */
    public const array CELLS = ['worked', 'overtime', 'night', 'unworked'];

    public const string PRESET_STANDARD = 'STANDARD';

    public const string PRESET_GOVERNMENT = 'GOVERNMENT';

    /**
     * The usual minimum for each cell, as a percent: a worked day, an
     * overtime hour, a night hour and an unworked scheduled day of each
     * type. A version may go below one with a reason; the shortfall is a
     * warning, never a refusal, because the minimums do not bind every kind
     * of employment. A null is a cell with no minimum (an unworked ordinary
     * day, a rest day and the holiday-on-a-rest-day rows, which the engine
     * never pays unworked).
     *
     * @var array<string, array{worked: float, overtime: float, night: float, unworked: ?float}>
     */
    public const array MINIMUMS = [
        'ORDINARY' => ['worked' => 100.0, 'overtime' => 125.0, 'night' => 110.0, 'unworked' => null],
        'REST' => ['worked' => 130.0, 'overtime' => 169.0, 'night' => 143.0, 'unworked' => null],
        'SPECIAL' => ['worked' => 130.0, 'overtime' => 169.0, 'night' => 143.0, 'unworked' => 0.0],
        'SPECIAL_REST' => ['worked' => 150.0, 'overtime' => 195.0, 'night' => 165.0, 'unworked' => null],
        'REGULAR' => ['worked' => 200.0, 'overtime' => 260.0, 'night' => 220.0, 'unworked' => 100.0],
        'REGULAR_REST' => ['worked' => 260.0, 'overtime' => 338.0, 'night' => 286.0, 'unworked' => null],
        'DOUBLE' => ['worked' => 300.0, 'overtime' => 390.0, 'night' => 330.0, 'unworked' => 200.0],
        'DOUBLE_REST' => ['worked' => 390.0, 'overtime' => 507.0, 'night' => 429.0, 'unworked' => null],
    ];

    /**
     * @param  array<string, array{worked: ?float, overtime: ?float, night: ?float, unworked: float}>  $matrix
     */
    private function __construct(
        public readonly string $basis,
        public readonly string $dailyRateMethod,
        public readonly float $dailyRateValue,
        public readonly bool $holidayNeedsAttendance,
        public readonly PayrollRates $rates,
        public readonly float $holidayOvertimeFactor,
        public readonly int $nightStart,
        public readonly int $nightEnd,
        public readonly bool $nightOnOvertime,
        public readonly array $matrix,
        public readonly ?float $hourlyHours = null,
        public readonly ?int $roundingDecimals = null,
        public readonly ?float $seaportRate = null,
        /** The net pay a payslip should not fall below, or null for no floor: payroll warns on a draft under it (no money moves). */
        public readonly ?float $minimumTakeHome = null,
    ) {}

    /**
     * A derived rate as the version wants it multiplied on: rounded to the
     * version's decimals, or exactly as derived when it sets none.
     */
    public function roundRate(float $rate): float
    {
        return $this->roundingDecimals === null ? $rate : Money::roundTo($rate, $this->roundingDecimals);
    }

    /**
     * The reference's rules over its Formulas rows: what every payroll
     * computed before Pay Basis & Rates existed.
     *
     * @param  list<mixed>  $formulas
     */
    public static function fromFormulas(array $formulas = []): self
    {
        return new self(
            self::BASIS_DAILY, self::METHOD_MONTH, 0.0, false,
            PayrollRates::fromFormulas($formulas), PayrollRates::HOLIDAY_OT_PREMIUM,
            ShiftResolver::NIGHT_WINDOW_START, ShiftResolver::NIGHT_WINDOW_END, false,
            self::emptyMatrix(),
        );
    }

    /**
     * Strictly from a saved version's figures.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $error = self::error($data);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
        $matrix = [];
        foreach (self::DAY_TYPES as $type) {
            $row = $data['matrix'][$type];
            $matrix[$type] = [
                'worked' => self::nullableFloat($row['worked']),
                'overtime' => self::nullableFloat($row['overtime']),
                'night' => self::nullableFloat($row['night']),
                'unworked' => (float) $row['unworked'],
            ];
        }

        return new self(
            (string) $data['basis'],
            (string) $data['daily_rate']['method'],
            (float) $data['daily_rate']['value'],
            (bool) $data['holiday_needs_attendance'],
            PayrollRates::of(
                (float) $data['overtime']['rate'], (float) $data['overtime']['max_hours'],
                (float) $data['night']['rate'], (float) $data['night']['max_hours'],
                (float) $data['rest_day']['rate'], (float) $data['rest_day']['max_hours'],
            ),
            (float) $data['holiday_overtime_factor'],
            (int) $data['night']['start'],
            (int) $data['night']['end'],
            (bool) $data['night']['on_overtime'],
            $matrix,
            // Divergence 16's figures: a version captured before them has
            // no key, which reads as blank — the reference's operations.
            self::nullableFloat($data['hourly_rate']['hours'] ?? null),
            ($data['rounding']['decimals'] ?? null) === null ? null : (int) $data['rounding']['decimals'],
            self::nullableFloat($data['overtime']['seaport_rate'] ?? null),
            self::nullableFloat($data['take_home']['minimum'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'basis' => $this->basis,
            'daily_rate' => ['method' => $this->dailyRateMethod, 'value' => $this->dailyRateValue],
            'holiday_needs_attendance' => $this->holidayNeedsAttendance,
            'overtime' => ['rate' => $this->rates->otRate, 'max_hours' => $this->rates->maxOtHours, 'seaport_rate' => $this->seaportRate],
            'hourly_rate' => ['hours' => $this->hourlyHours],
            'rounding' => ['decimals' => $this->roundingDecimals],
            'take_home' => ['minimum' => $this->minimumTakeHome],
            'night' => [
                'rate' => $this->rates->nsdRate, 'max_hours' => $this->rates->maxNsdHours,
                'start' => $this->nightStart, 'end' => $this->nightEnd, 'on_overtime' => $this->nightOnOvertime,
            ],
            'rest_day' => ['rate' => $this->rates->restDayRate, 'max_hours' => $this->rates->maxRestDayHours],
            'holiday_overtime_factor' => $this->holidayOvertimeFactor,
            'matrix' => $this->matrix,
        ];
    }

    /**
     * A preset's figures, as a saved version would hold them.
     *
     * @return array<string, mixed>
     */
    public static function preset(string $name, ?self $from = null): array
    {
        $base = ($from ?? self::fromFormulas())->toArray();
        if ($name === self::PRESET_STANDARD) {
            // The reference's figures and derivations, whatever the version
            // in force had changed, plus the usual pay for an unworked
            // regular holiday — the one place the reference pays nothing
            // where the usual minimum pays a day — behind the attendance
            // rule.
            $standard = self::fromFormulas()->toArray();
            $standard['holiday_needs_attendance'] = true;
            $standard['matrix'] = self::emptyMatrix();
            $standard['matrix']['REGULAR']['unworked'] = 100.0;
            $standard['matrix']['DOUBLE']['unworked'] = 200.0;

            return $standard;
        }
        if ($name !== self::PRESET_GOVERNMENT) {
            throw new InvalidArgumentException('Unknown preset.');
        }
        // The salary covers every day; the hourly rate is monthly ÷ 22 ÷ the
        // shift's hours; rest-day and holiday hours are paid at 150% on top of
        // the salary, overtime at 125%; night hours from 6 PM earn 20% more,
        // overtime hours included.
        $base['basis'] = self::BASIS_MONTHLY;
        $base['daily_rate'] = ['method' => self::METHOD_DIVISOR, 'value' => 22.0];
        $base['holiday_needs_attendance'] = false;
        $base['overtime']['rate'] = 1.25;
        $base['night'] = [...$base['night'], 'rate' => 0.2, 'start' => 18 * 60, 'end' => 30 * 60, 'on_overtime' => true];
        $base['rest_day']['rate'] = 1.5;
        $base['holiday_overtime_factor'] = 1.0;
        $base['matrix'] = [
            'ORDINARY' => ['worked' => 100.0, 'overtime' => 125.0, 'night' => 120.0, 'unworked' => 0.0],
            'REST' => ['worked' => 150.0, 'overtime' => 150.0, 'night' => 170.0, 'unworked' => 0.0],
            'SPECIAL' => ['worked' => 250.0, 'overtime' => 150.0, 'night' => 270.0, 'unworked' => 100.0],
            'SPECIAL_REST' => ['worked' => 250.0, 'overtime' => 150.0, 'night' => 270.0, 'unworked' => 100.0],
            'REGULAR' => ['worked' => 250.0, 'overtime' => 150.0, 'night' => 270.0, 'unworked' => 100.0],
            'REGULAR_REST' => ['worked' => 250.0, 'overtime' => 150.0, 'night' => 270.0, 'unworked' => 100.0],
            'DOUBLE' => ['worked' => 250.0, 'overtime' => 150.0, 'night' => 270.0, 'unworked' => 100.0],
            'DOUBLE_REST' => ['worked' => 250.0, 'overtime' => 150.0, 'night' => 270.0, 'unworked' => 100.0],
        ];

        return $base;
    }

    /** Why a version's figures cannot be used, or null. */
    public static function error(mixed $data): ?string
    {
        if (! is_array($data)) {
            return 'The pay rules are missing.';
        }
        foreach (['basis', 'daily_rate', 'holiday_needs_attendance', 'overtime', 'night', 'rest_day', 'holiday_overtime_factor', 'matrix'] as $key) {
            if (! array_key_exists($key, $data)) {
                return "The pay rules are incomplete ({$key}).";
            }
        }
        if (! in_array($data['basis'], [self::BASIS_DAILY, self::BASIS_MONTHLY], true)) {
            return 'Choose the pay basis.';
        }
        $method = $data['daily_rate']['method'] ?? null;
        $value = $data['daily_rate']['value'] ?? null;
        if (! in_array($method, [self::METHOD_MONTH, self::METHOD_FACTOR, self::METHOD_DIVISOR], true) || ! self::finite($value)) {
            return 'Choose how the daily rate is derived.';
        }
        if ($method === self::METHOD_FACTOR && ((float) $value < 1.0 || (float) $value > 366.0)) {
            return 'The yearly factor must be between 1 and 366 days.';
        }
        if ($method === self::METHOD_DIVISOR && ((float) $value < 1.0 || (float) $value > 31.0)) {
            return 'The divisor must be between 1 and 31 days.';
        }
        if ($method === self::METHOD_MONTH && (float) $value !== 0.0) {
            return 'The month\'s working days need no divisor.';
        }
        if (! is_bool($data['holiday_needs_attendance'])) {
            return 'Say whether holiday pay needs attendance the day before.';
        }
        foreach (['overtime' => 'Overtime', 'night' => 'Night', 'rest_day' => 'Rest day'] as $key => $label) {
            $group = $data[$key];
            if (! is_array($group) || ! self::finite($group['rate'] ?? null) || (float) $group['rate'] < 0.0 || (float) $group['rate'] > 10.0) {
                return "{$label}: enter a rate between 0% and 1,000%.";
            }
            if (! self::finite($group['max_hours'] ?? null) || (float) $group['max_hours'] < 0.0 || (float) $group['max_hours'] > 24.0) {
                return "{$label}: enter hours between 0 and 24.";
            }
        }
        $night = $data['night'];
        if (! is_int($night['start'] ?? null) || ! is_int($night['end'] ?? null) || ! is_bool($night['on_overtime'] ?? null)
            || $night['start'] < 0 || $night['start'] >= 1440 || $night['end'] <= $night['start'] || $night['end'] > $night['start'] + 1440) {
            return 'Night work: enter when it starts and ends.';
        }
        if (! self::finite($data['holiday_overtime_factor']) || (float) $data['holiday_overtime_factor'] < 0.0 || (float) $data['holiday_overtime_factor'] > 10.0) {
            return 'Enter a holiday overtime factor between 0 and 10.';
        }
        $seaport = $data['overtime']['seaport_rate'] ?? null;
        if ($seaport !== null && (! self::finite($seaport) || (float) $seaport < 0.0 || (float) $seaport > 10.0)) {
            return 'Seaport overtime: enter a rate between 0% and 1,000%, or leave it blank.';
        }
        $hours = $data['hourly_rate']['hours'] ?? null;
        if ($hours !== null && (! self::finite($hours) || (float) $hours < 1.0 || (float) $hours > 24.0)) {
            return 'Hours for the hourly rate: enter between 1 and 24, or leave it blank.';
        }
        $decimals = $data['rounding']['decimals'] ?? null;
        if ($decimals !== null && (! is_int($decimals) || $decimals < 0 || $decimals > 4)) {
            return 'Rounding: choose 0 to 4 decimals, or leave it blank.';
        }
        $minimum = $data['take_home']['minimum'] ?? null;
        if ($minimum !== null && (! self::finite($minimum) || (float) $minimum < 0.0 || (float) $minimum > 99_999_999.0)) {
            return 'Minimum take-home pay: enter an amount at or above zero, or leave it blank.';
        }
        $matrix = $data['matrix'];
        if (! is_array($matrix)) {
            return 'The rates by day type are missing.';
        }
        foreach (self::DAY_TYPES as $type) {
            $row = $matrix[$type] ?? null;
            if (! is_array($row)) {
                return "The rates for {$type} days are missing.";
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== ['night', 'overtime', 'unworked', 'worked']) {
                return "The rates for {$type} days are incomplete.";
            }
            foreach (['worked', 'overtime', 'night'] as $cell) {
                if ($row[$cell] !== null && (! self::finite($row[$cell]) || (float) $row[$cell] < 0.0 || (float) $row[$cell] > 1000.0)) {
                    return "Enter a percentage between 0 and 1,000 for the {$type} {$cell} rate.";
                }
            }
            if (! self::finite($row['unworked']) || (float) $row['unworked'] < 0.0 || (float) $row['unworked'] > 1000.0) {
                return "Enter a percentage between 0 and 1,000 for an unworked {$type} day.";
            }
            if ($row['night'] !== null && $row['worked'] !== null && (float) $row['night'] < (float) $row['worked']) {
                return "The {$type} night rate cannot be below its worked rate.";
            }
        }

        return null;
    }

    /**
     * The cells below their usual minimum, as the engine would price them:
     * an explicit cell as it stands, a blank ordinary or rest-day cell as
     * the figures derive it, a blank holiday cell not at all (the holiday's
     * own percent decides). An unworked day counts only under the DAILY
     * basis, where it is what the cell pays.
     *
     * @return list<array{type: string, cell: string, value: float, minimum: float}>
     */
    public function shortfalls(): array
    {
        $found = [];
        foreach (self::MINIMUMS as $type => $minimums) {
            foreach (self::CELLS as $cell) {
                $minimum = $minimums[$cell];
                if ($minimum === null) {
                    continue;
                }
                $value = $this->effectivePercent($type, $cell);
                if ($value !== null && $value < $minimum) {
                    $found[] = ['type' => $type, 'cell' => $cell, 'value' => $value, 'minimum' => $minimum];
                }
            }
        }

        return $found;
    }

    /**
     * What a cell pays as a percent, or null when only the day's holiday
     * can say. The ordinary and rest-day derivations are the engine's own
     * (the night hour is the worked rate plus the night add-on on the base
     * hourly rate).
     */
    public function effectivePercent(string $type, string $cell): ?float
    {
        $row = $this->matrix[$type];
        if ($cell === 'unworked') {
            return $this->basis === self::BASIS_DAILY ? $row['unworked'] : null;
        }
        if ($row[$cell] !== null) {
            return $row[$cell];
        }
        $worked = match ($type) {
            'ORDINARY' => 100.0,
            'REST' => $row['worked'] ?? $this->rates->restDayRate * 100,
            default => null,
        };
        if ($worked === null) {
            return null;
        }

        return match ($cell) {
            'worked' => $worked,
            'overtime' => $type === 'ORDINARY' ? $this->rates->otRate * 100 : $this->rates->restDayRate * $this->rates->otRate * 100,
            default => $worked + $this->rates->nsdRate * 100,
        };
    }

    /** Whether a day of this type has an explicit worked rate (a double holiday is priced only then). */
    public function prices(string $type): bool
    {
        return ($this->matrix[$type]['worked'] ?? null) !== null;
    }

    /** @return array<string, array{worked: null, overtime: null, night: null, unworked: float}> */
    public static function emptyMatrix(): array
    {
        $matrix = [];
        foreach (self::DAY_TYPES as $type) {
            $matrix[$type] = ['worked' => null, 'overtime' => null, 'night' => null, 'unworked' => 0.0];
        }

        return $matrix;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
