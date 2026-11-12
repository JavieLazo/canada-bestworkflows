<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

use App\Modules\Timekeeping\Domain\Absence\LeaveApplication;

/**
 * The settings of one leave type, as HR sets them on Leave Settings: HOW IT
 * IS FILED — against the employee's earned credits, a set number of days
 * each year, a set number of days each time, or always without pay — with
 * its set days and whether a request is counted in scheduled working days
 * or calendar days; and, for a type filed against credits, whether it EARNS
 * credits, its UNIT, whether lateness and undertime or absences may be
 * CHARGED to it, the ORDER it is charged in among the types that may, how
 * far BELOW ZERO its balance may go — by a payroll's charge or by leave
 * approved with pay (LeavePay); 0, the default, keeps it at zero or above
 * — whether its credits may be CASHED OUT and within what LIMITS
 * (LeaveCashOutLimits), whether its balance is PAID OUT ON SEPARATION
 * (terminal leave), and what its balance does at the YEAR END. Every type
 * also says which DETAILS OF LEAVE a request for it
 * asks (LeaveApplication: where a vacation is spent, the illness, the
 * study leave's purpose, or nothing), and WHO MAY USE IT
 * (LeaveEligibility: employment statuses and sex).
 *
 * Whatever the credits (down to that limit) or the set days do not cover
 * is approved WITHOUT PAY; nothing here refuses a filing. Only a type filed
 * against credits earns, is charged, goes below zero or is cashed out: the
 * other three hold no credits.
 *
 * The pure shape and its validation; the command stores it on
 * `leave_types`. Every figure is HR's: none is shipped and none is checked
 * against a law or a regulation.
 */
final class LeaveTypeCredits
{
    /** Deducted from the employee's earned credits of the type. */
    public const string FILING_CREDITS = 'CREDITS';

    /** A set number of days each calendar year, counted from the requests approved in it. */
    public const string FILING_YEARLY = 'YEARLY';

    /** A set number of days each time the leave is filed. */
    public const string FILING_OCCASION = 'OCCASION';

    /** Always without pay; no credits. */
    public const string FILING_UNPAID = 'UNPAID';

    public const array FILINGS = [self::FILING_CREDITS, self::FILING_YEARLY, self::FILING_OCCASION, self::FILING_UNPAID];

    /** @var array<string, string> the payment basis, as Leave Settings names it */
    public const array FILING_LABELS = [
        self::FILING_CREDITS => 'Earned leave credits',
        self::FILING_YEARLY => 'Fixed annual allowance',
        self::FILING_OCCASION => 'Fixed allowance per request',
        self::FILING_UNPAID => 'Unpaid leave',
    ];

    /** The employee's scheduled working days of the range, holidays out. */
    public const string DAYS_WORKING = 'WORKING';

    /** Every calendar day of the range. */
    public const string DAYS_CALENDAR = 'CALENDAR';

    public const array DAY_COUNTS = [self::DAYS_WORKING, self::DAYS_CALENDAR];

    /** @var array<string, string> */
    public const array DAY_COUNT_LABELS = [self::DAYS_WORKING => 'Scheduled working days', self::DAYS_CALENDAR => 'Calendar days'];

    public const string UNIT_DAYS = 'DAYS';

    public const string UNIT_HOURS = 'HOURS';

    public const array UNITS = [self::UNIT_DAYS, self::UNIT_HOURS];

    /** @var array<string, string> */
    public const array UNIT_LABELS = [self::UNIT_DAYS => 'Days', self::UNIT_HOURS => 'Hours'];

    public const int MAX_ORDER = 999;

    /** What the balance does when the leave year closes: carries whole (a debt included) or lapses above zero. */
    public const string YEAR_END_CARRY = 'CARRY';

    public const string YEAR_END_LAPSE = 'LAPSE';

    public const array YEAR_ENDS = [self::YEAR_END_CARRY, self::YEAR_END_LAPSE];

    /** @var array<string, string> */
    public const array YEAR_END_LABELS = [self::YEAR_END_CARRY => 'Carry forward', self::YEAR_END_LAPSE => 'Expire unused credits'];

    public function __construct(
        public readonly bool $earnsCredits,
        public readonly string $unit,
        public readonly bool $chargeLate,
        public readonly bool $chargeAbsence,
        public readonly int $chargeOrder,
        public readonly float $belowZeroLimit,
        public readonly bool $canCashOut = false,
        public readonly string $yearEnd = self::YEAR_END_CARRY,
        public readonly string $filing = self::FILING_CREDITS,
        /** The set days of a YEARLY or OCCASION type; null for the other two. */
        public readonly ?float $allowanceDays = null,
        public readonly string $dayCount = self::DAYS_WORKING,
        /** Which details of leave a request asks (LeaveApplication::SECTIONS). */
        public readonly string $leaveDetails = LeaveApplication::NONE,
        /** The limits on converting the credits to cash; none unless the type may be cashed out. */
        public readonly LeaveCashOutLimits $cashOutLimits = new LeaveCashOutLimits,
        /** Whether the remaining balance is paid out when an employee leaves (terminal leave). */
        public readonly bool $paidOnSeparation = false,
        /** Who may use the type: every employment status or the chosen ones, anyone or one sex. */
        public readonly LeaveEligibility $eligibility = new LeaveEligibility,
    ) {}

    /** Today's behaviour: filed against credits, nothing earns, nothing is charged, nothing is cashed out. */
    public static function none(): self
    {
        return new self(false, self::UNIT_DAYS, false, false, 0, 0.0, false);
    }

    /** Whether the type is filed against the employee's earned credits — the only kind that earns, is charged or is cashed out. */
    public function filedAgainstCredits(): bool
    {
        return $this->filing === self::FILING_CREDITS;
    }

    /** Whether the type gives a set number of days (each year, or each time). */
    public function hasAllowance(): bool
    {
        return in_array($this->filing, [self::FILING_YEARLY, self::FILING_OCCASION], true);
    }

    /** Whether lateness or absences are charged to the type — what puts it in the charge order. */
    public function charged(): bool
    {
        return $this->chargeLate || $this->chargeAbsence;
    }

    /**
     * EACH CHARGED TYPE HOLDS ITS OWN CHARGE ORDER: two types charged for
     * lateness or absences never share a number, so which one is charged
     * first is never left to their names. A type charged for nothing is in
     * no order, so its number clashes with nothing.
     *
     * @param  array<string, array{name: string, rules: self}>  $types  key => the type's name and settings
     * @return array<string, list<string>> key => the names of the other charged types holding its number, for each type that shares one
     */
    public static function orderClashes(array $types): array
    {
        $byOrder = [];
        foreach ($types as $key => $entry) {
            if ($entry['rules']->charged()) {
                $byOrder[$entry['rules']->chargeOrder][] = (string) $key;
            }
        }
        $out = [];
        foreach ($byOrder as $keys) {
            if (count($keys) < 2) {
                continue;
            }
            foreach ($keys as $key) {
                $out[$key] = array_values(array_map(static fn (string $other): string => $types[$other]['name'], array_filter($keys, static fn (string $other): bool => $other !== $key)));
            }
        }

        return $out;
    }

    /**
     * "Deduction priority 1 is already used by Sick Leave. Each leave type with
     * credit deductions needs its own priority." — the refusal and the dialog's
     * warning alike. The priority is the charge order the payroll charges in.
     *
     * @param  list<string>  $names  the other types holding the number
     */
    public static function orderClashText(int $order, array $names): string
    {
        $last = array_pop($names);
        $list = $names === [] ? (string) $last : implode(', ', $names).' and '.$last;

        return "Deduction priority {$order} is already used by {$list}. Each leave type with credit deductions needs its own priority.";
    }

    /**
     * Why typed settings are refused, or null when they are acceptable.
     * `can_cash_out`, `paid_on_separation`, `year_end`, `filing`, `day_count`
     * and `leave_details` may be left out (no, no, carries, earned credits,
     * working days, none), but given they must hold one of their values. A
     * type with set days needs its number of days; a type that may be cashed
     * out, valid limits (LeaveCashOutLimits, each one blank for none).
     *
     * @param  array<string, mixed>  $input
     */
    public static function error(array $input): ?string
    {
        foreach (['earns_credits', 'charge_late', 'charge_absence'] as $flag) {
            if (! is_bool($input[$flag] ?? null)) {
                return 'Choose yes or no for each rule.';
            }
        }
        if (array_key_exists('can_cash_out', $input) && ! is_bool($input['can_cash_out'])) {
            return 'Choose yes or no for each rule.';
        }
        if (array_key_exists('paid_on_separation', $input) && ! is_bool($input['paid_on_separation'])) {
            return 'Choose yes or no for each rule.';
        }
        if (array_key_exists('year_end', $input) && ! in_array($input['year_end'], self::YEAR_ENDS, true)) {
            return 'Choose whether credits carry forward or expire at year end.';
        }
        if (array_key_exists('filing', $input) && ! in_array($input['filing'], self::FILINGS, true)) {
            return 'Choose the payment basis.';
        }
        if (array_key_exists('day_count', $input) && ! in_array($input['day_count'], self::DAY_COUNTS, true)) {
            return 'Choose whether a request counts working days or calendar days.';
        }
        if (array_key_exists('leave_details', $input) && ! in_array($input['leave_details'], LeaveApplication::SECTIONS, true)) {
            return 'Choose which details of leave a request asks for.';
        }
        $filing = $input['filing'] ?? self::FILING_CREDITS;
        if (in_array($filing, [self::FILING_YEARLY, self::FILING_OCCASION], true)) {
            $days = $input['allowance_days'] ?? null;
            $daysError = LeaveQuantity::error($days ?? '');
            if ($daysError !== null) {
                return 'Allowance days: '.$daysError;
            }
            if ((LeaveQuantity::parse($days) ?? 0.0) <= 0.0) {
                return 'Enter the '.($filing === self::FILING_YEARLY ? 'annual allowance' : 'allowance per request').' in days.';
            }
        }
        if (! in_array($input['unit'] ?? null, self::UNITS, true)) {
            return 'Choose the credit unit: days or hours.';
        }
        $order = $input['charge_order'] ?? null;
        if (! is_int($order) && ! (is_string($order) && preg_match('/\A\d{1,3}\z/', trim($order)) === 1)) {
            return 'Enter the deduction priority as a whole number from 0 to '.self::MAX_ORDER.'.';
        }
        if ((int) $order > self::MAX_ORDER) {
            return 'Enter the deduction priority as a whole number from 0 to '.self::MAX_ORDER.'.';
        }
        $limit = LeaveQuantity::error($input['below_zero_limit'] ?? '');
        if ($limit !== null) {
            return 'Maximum negative balance: '.$limit;
        }
        if ($filing === self::FILING_CREDITS && ($input['can_cash_out'] ?? false) === true) {
            return LeaveCashOutLimits::error($input);
        }

        return null;
    }

    /**
     * The settings a validated input stands for. A type NOT filed against
     * credits holds none, so it never earns, is never charged, is never
     * cashed out and is never paid out on separation, whatever the input
     * said; set days are kept only for a type that has them, and cash
     * conversion limits only for a type that may be cashed out. Who may use
     * the type is read from `status_mode`, `statuses` and `sex`, which
     * LeaveEligibility::error validates against the Employment list (the
     * command holds the list; this shape does not).
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $filing = (string) ($input['filing'] ?? self::FILING_CREDITS);
        $credits = $filing === self::FILING_CREDITS;
        $allowance = in_array($filing, [self::FILING_YEARLY, self::FILING_OCCASION], true) ? LeaveQuantity::parse($input['allowance_days'] ?? '') : null;
        $cashOut = $credits && (bool) ($input['can_cash_out'] ?? false);

        return new self(
            $credits && (bool) $input['earns_credits'],
            (string) $input['unit'],
            $credits && (bool) $input['charge_late'],
            $credits && (bool) $input['charge_absence'],
            (int) $input['charge_order'],
            LeaveQuantity::parse($input['below_zero_limit'] ?? '') ?? 0.0,
            $cashOut,
            (string) ($input['year_end'] ?? self::YEAR_END_CARRY),
            $filing,
            $allowance,
            (string) ($input['day_count'] ?? self::DAYS_WORKING),
            (string) ($input['leave_details'] ?? LeaveApplication::NONE),
            $cashOut ? LeaveCashOutLimits::fromInput($input) : new LeaveCashOutLimits,
            $credits && (bool) ($input['paid_on_separation'] ?? false),
            LeaveEligibility::fromInput($input),
        );
    }

    /**
     * The columns on `leave_types`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'earns_credits' => $this->earnsCredits,
            'unit' => $this->unit,
            'charge_late' => $this->chargeLate,
            'charge_absence' => $this->chargeAbsence,
            'charge_order' => $this->chargeOrder,
            'below_zero_limit' => $this->belowZeroLimit,
            'can_cash_out' => $this->canCashOut,
            'year_end' => $this->yearEnd,
            'filing' => $this->filing,
            'allowance_days' => $this->allowanceDays,
            'day_count' => $this->dayCount,
            'leave_details' => $this->leaveDetails,
            ...$this->cashOutLimits->toArray(),
            'paid_on_separation' => $this->paidOnSeparation,
            ...$this->eligibility->toArray(),
        ];
    }

    /** "3 days each year" / "105 days each time" / "earned credits" / "without pay": how the type is filed, in words. */
    public function filingText(): string
    {
        $days = $this->allowanceDays === null ? '' : LeaveQuantity::format($this->allowanceDays).' '.($this->allowanceDays == 1.0 ? 'day' : 'days');

        return match ($this->filing) {
            self::FILING_YEARLY => $days.' each year',
            self::FILING_OCCASION => $days.' each time',
            self::FILING_UNPAID => 'without pay',
            default => 'earned credits',
        };
    }

    /**
     * "earns · days · charged for lateness and absences, 1st · down to −2 · may be cashed out (at least 10 days at a time) · paid on separation · asks Sick Leave details · for Permanent, Casual" for an audit sentence.
     *
     * @param  array<string, string>  $statusNames  Employment list id => name, to name who may use the type
     */
    public function describe(array $statusNames = []): string
    {
        $counted = ($this->dayCount === self::DAYS_CALENDAR ? ' · counts calendar days' : '')
            .($this->leaveDetails === LeaveApplication::NONE ? '' : ' · asks '.(LeaveApplication::SECTION_LABELS[$this->leaveDetails] ?? $this->leaveDetails).' details')
            .($this->eligibility->isEveryone() ? '' : ' · for '.$this->eligibility->describe($statusNames));
        if (! $this->filedAgainstCredits()) {
            return 'filed as '.$this->filingText().$counted;
        }
        $charged = [];
        if ($this->chargeLate) {
            $charged[] = 'lateness';
        }
        if ($this->chargeAbsence) {
            $charged[] = 'absences';
        }

        return ($this->earnsCredits ? 'earns credits' : 'does not earn credits')
            .' · '.strtolower(self::UNIT_LABELS[$this->unit] ?? $this->unit)
            .' · '.($charged === [] ? 'never charged' : 'charged for '.implode(' and ', $charged).', order '.$this->chargeOrder)
            .' · below zero to '.LeaveQuantity::format($this->belowZeroLimit)
            .($this->canCashOut ? ' · may be cashed out'.($this->cashOutLimits->isNone() ? '' : ' ('.$this->cashOutLimits->describe().')') : '')
            .($this->paidOnSeparation ? ' · paid on separation' : '')
            .($this->yearEnd === self::YEAR_END_LAPSE ? ' · lapses at year end' : '')
            .$counted;
    }
}
