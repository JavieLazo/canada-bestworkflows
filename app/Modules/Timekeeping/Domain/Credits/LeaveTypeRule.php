<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

/**
 * ONE LEAVE TYPE'S RULE, the same for every employee: the pure shape a
 * dated version of `leave_rules` holds, validated strictly.
 *
 *   waiting_months   how many months after the date hired the type is
 *                    ACQUIRED, or null when it is the employee's from the
 *                    first day
 *   waiting_extends  whether days without pay during the wait — absences
 *                    and approved leave without pay — push the day back,
 *                    one day each (LeaveAcquisitionRule)
 *   on_acquire       what the employee holds on the day it is acquired:
 *                    ZERO (nothing; earning starts there) or GRANT (the
 *                    monthly amount × the months waited)
 *   earning          what a month earns once the type is acquired — the
 *                    shape LeavePolicy holds per type (amount, method,
 *                    lookup rows, part-month rule, balance cap) — or null
 *                    for a type that earns nothing
 *   fallback         the leave type a request draws on when this type's
 *                    credits run out, or null
 *
 * Nothing here reads a clock, a database or a screen, and no figure is
 * shipped: every value is what the leave manager entered.
 */
final class LeaveTypeRule
{
    public const string ZERO = 'ZERO';

    public const string GRANT = 'GRANT';

    public const array ON_ACQUIRES = [self::ZERO, self::GRANT];

    /** @var array<string, string> */
    public const array ON_ACQUIRE_LABELS = [
        self::ZERO => 'Start from zero',
        self::GRANT => 'Grant the credits of the months waited',
    ];

    public const int MAX_MONTHS = 600;

    /** @param  array{waiting_months: ?int, waiting_extends: bool, on_acquire: string, earning: ?array{amount: float, method: string, table: list<array{up_to: float, credits: float}>, partial: string, cap: ?float}, fallback: ?string}  $params */
    private function __construct(public readonly array $params) {}

    /** A type with no rule: the employee's from the first day, earning nothing, falling back on nothing. */
    public static function none(): self
    {
        return new self(['waiting_months' => null, 'waiting_extends' => false, 'on_acquire' => self::ZERO, 'earning' => null, 'fallback' => null]);
    }

    /**
     * Why typed params are refused, or null when they are acceptable.
     *
     * @param  array<string, mixed>  $params
     * @param  string  $token  the leave type the rule is for
     * @param  bool  $earns  whether that type is filed against earned credits, the only kind that earns or falls back
     * @param  list<string>  $tokens  the leave types a fallback may name: those filed against earned credits
     */
    public static function error(array $params, string $token, bool $earns, array $tokens): ?string
    {
        $months = $params['waiting_months'] ?? null;
        $waits = $months !== null && trim((string) $months) !== '';
        if ($waits && ! (is_int($months) || (is_string($months) && preg_match('/\A\d{1,3}\z/', trim($months)) === 1))) {
            return 'Enter the waiting period as a whole number of months.';
        }
        if ($waits && ((int) $months < 1 || (int) $months > self::MAX_MONTHS)) {
            return 'Enter the waiting period as 1 to '.self::MAX_MONTHS.' months, or turn the waiting period off.';
        }
        if (! is_bool($params['waiting_extends'] ?? null)) {
            return 'Choose whether unpaid days extend the waiting period.';
        }
        if (! in_array($params['on_acquire'] ?? null, self::ON_ACQUIRES, true)) {
            return 'Choose what the employee holds on the day the leave is acquired.';
        }
        $earning = $params['earning'] ?? null;
        if ($earning !== null) {
            if (! $earns) {
                return 'Only a leave type paid from earned leave credits accrues credits. Set its payment basis to "Earned leave credits" first.';
            }
            $ruleError = LeavePolicy::earningRuleError($earning);
            if ($ruleError !== null) {
                return $ruleError;
            }
        }
        if ($waits && $params['on_acquire'] === self::GRANT && $earning === null) {
            return 'Granting the credits of the months waited needs a monthly accrual to work from.';
        }
        $fallback = $params['fallback'] ?? null;
        if ($fallback !== null && $fallback !== '') {
            if (! $earns) {
                return 'Only a leave type paid from earned leave credits can fall back on another.';
            }
            if (! is_string($fallback) || ! in_array($fallback, $tokens, true)) {
                return 'Choose a leave type from Leave Settings to fall back on.';
            }
            if ($fallback === $token) {
                return 'A leave type cannot fall back on itself.';
            }
        }

        return null;
    }

    /**
     * The params as stored, once error() has passed: numbers settled, and
     * the waiting options kept only while there is a waiting period.
     *
     * @param  array<string, mixed>  $params
     */
    public static function fromParams(array $params): self
    {
        $months = $params['waiting_months'] ?? null;
        $months = $months === null || trim((string) $months) === '' ? null : (int) $months;
        $earning = $params['earning'] ?? null;
        $fallback = $params['fallback'] ?? null;

        return new self([
            'waiting_months' => $months,
            'waiting_extends' => $months !== null && (bool) ($params['waiting_extends'] ?? false),
            'on_acquire' => $months !== null && ($params['on_acquire'] ?? self::ZERO) === self::GRANT ? self::GRANT : self::ZERO,
            'earning' => is_array($earning) ? LeavePolicy::earningRule($earning) : null,
            'fallback' => is_string($fallback) && $fallback !== '' ? $fallback : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->params;
    }

    /** The months waited before the type is acquired; null when there is no waiting period. */
    public function waitingMonths(): ?int
    {
        return $this->params['waiting_months'];
    }

    public function extendsWait(): bool
    {
        return $this->params['waiting_extends'];
    }

    /** @return array{amount: float, method: string, table: list<array{up_to: float, credits: float}>, partial: string, cap: ?float}|null */
    public function earning(): ?array
    {
        return $this->params['earning'];
    }

    public function fallback(): ?string
    {
        return $this->params['fallback'];
    }

    /**
     * The credits granted on the day the type is acquired: the monthly
     * amount × the months waited under GRANT, nothing otherwise.
     */
    public function grant(): float
    {
        $months = $this->waitingMonths();
        $earning = $this->earning();
        if ($months === null || $earning === null || $this->params['on_acquire'] !== self::GRANT) {
            return 0.0;
        }

        return LeaveQuantity::normalize($earning['amount'] * $months);
    }

    /**
     * "acquired after 6 months, extended by days without pay · starts from
     * zero · earns 1.25 a month · falls back on Vacation Leave" for the
     * audit trail, whose sentences keep this wording.
     *
     * @param  array<string, string>  $names  token => leave type name
     */
    public function describe(array $names): string
    {
        return $this->sentence($names, audit: true);
    }

    /**
     * "acquired after 6 months, extended by unpaid days · starts from zero ·
     * accrues 1.25 a month · falls back on Vacation Leave" for the Leave
     * Settings list, in Leave Settings' own terms.
     *
     * @param  array<string, string>  $names  token => leave type name
     */
    public function summary(array $names): string
    {
        return $this->sentence($names, audit: false);
    }

    /** @param  array<string, string>  $names  token => leave type name */
    private function sentence(array $names, bool $audit): string
    {
        $months = $this->waitingMonths();
        $parts = [];
        if ($months === null) {
            $parts[] = 'available from the first day';
        } else {
            $parts[] = 'acquired after '.$months.($months === 1 ? ' month' : ' months').($this->extendsWait() ? ($audit ? ', extended by days without pay' : ', extended by unpaid days') : '');
            $parts[] = $this->grant() > 0.0 ? LeaveQuantity::format($this->grant()).' granted on acquiring' : 'starts from zero';
        }
        $earning = $this->earning();
        $earns = $audit ? 'earns' : 'accrues';
        $parts[] = $earning === null ? $earns.' nothing' : $earns.' '.LeaveQuantity::format($earning['amount']).' a month'.match ($earning['method']) {
            'PRO_RATA' => ' (pro-rata)', 'TABLE' => ' (lookup table)', default => '',
        };
        if ($this->fallback() !== null) {
            $parts[] = 'falls back on '.($names[$this->fallback()] ?? $this->fallback());
        }

        return implode(' · ', $parts);
    }
}
