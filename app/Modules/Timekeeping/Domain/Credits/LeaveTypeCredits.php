<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

/**
 * The credit rules of one leave type, as HR sets them on Leave Credits
 * settings: whether the type EARNS credits by the employee's policy, its
 * UNIT, whether lateness and undertime or absences may be CHARGED to it,
 * the ORDER it is charged in among the types that may, how far BELOW
 * ZERO a charge may take it, and whether its credits may be CASHED OUT.
 * The pure shape and its validation; the command stores it on
 * `leave_types`.
 */
final class LeaveTypeCredits
{
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
    public const array YEAR_END_LABELS = [self::YEAR_END_CARRY => 'Carries over', self::YEAR_END_LAPSE => 'Lapses at year end'];

    public function __construct(
        public readonly bool $earnsCredits,
        public readonly string $unit,
        public readonly bool $chargeLate,
        public readonly bool $chargeAbsence,
        public readonly int $chargeOrder,
        public readonly float $belowZeroLimit,
        public readonly bool $canCashOut = false,
        public readonly string $yearEnd = self::YEAR_END_CARRY,
    ) {}

    /** Today's behaviour: nothing earns, nothing is charged, nothing is cashed out. */
    public static function none(): self
    {
        return new self(false, self::UNIT_DAYS, false, false, 0, 0.0, false);
    }

    /**
     * Why typed settings are refused, or null when they are acceptable.
     * `can_cash_out` may be left out (no), but given it must be yes or no.
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
        if (array_key_exists('year_end', $input) && ! in_array($input['year_end'], self::YEAR_ENDS, true)) {
            return 'Choose whether the balance carries over or lapses at year end.';
        }
        if (! in_array($input['unit'] ?? null, self::UNITS, true)) {
            return 'Choose whether this type is kept in days or hours.';
        }
        $order = $input['charge_order'] ?? null;
        if (! is_int($order) && ! (is_string($order) && preg_match('/\A\d{1,3}\z/', trim($order)) === 1)) {
            return 'Enter the charge order as a whole number from 0 to '.self::MAX_ORDER.'.';
        }
        if ((int) $order > self::MAX_ORDER) {
            return 'Enter the charge order as a whole number from 0 to '.self::MAX_ORDER.'.';
        }
        $limit = LeaveQuantity::error($input['below_zero_limit'] ?? '');
        if ($limit !== null) {
            return 'Below zero limit: '.$limit;
        }

        return null;
    }

    /**
     * The settings a validated input stands for.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        return new self(
            (bool) $input['earns_credits'],
            (string) $input['unit'],
            (bool) $input['charge_late'],
            (bool) $input['charge_absence'],
            (int) $input['charge_order'],
            LeaveQuantity::parse($input['below_zero_limit'] ?? '') ?? 0.0,
            (bool) ($input['can_cash_out'] ?? false),
            (string) ($input['year_end'] ?? self::YEAR_END_CARRY),
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
        ];
    }

    /** "earns · days · charged for lateness and absences, 1st · down to −2 · may be cashed out" for an audit sentence. */
    public function describe(): string
    {
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
            .($this->canCashOut ? ' · may be cashed out' : '')
            .($this->yearEnd === self::YEAR_END_LAPSE ? ' · lapses at year end' : '');
    }
}
