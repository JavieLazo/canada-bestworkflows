<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Payroll\PayrollPeriod;
use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeRule;

/**
 * Every APPROVED leave rule version, read once and answered from memory:
 * which version of a leave type's rule is in force on a date, what it says
 * then, and the one LeavePolicy the rules add up to for everybody — each
 * earning type's monthly rule and the charge orders the leave types' credit
 * rules give, under the fixed terms policyOn() names. The earning job and
 * payroll's charge read walk many employees and months; they build one
 * book and ask it, instead of querying per employee.
 *
 * A version is what a type's rule was from the day it was saved; the
 * screen shows only the one in force today. The rules are IN FORCE from
 * the earliest effective date of any version; before that day nobody has
 * terms (LeaveRules).
 */
final class LeaveRuleBook
{
    /**
     * @param  array<string, list<array{id: string, from: string, params: array<string, mixed>}>>  $versions  scope => its approved versions, oldest first
     * @param  array<string, array{name: string, rules: LeaveTypeCredits}>  $types  every leave type's credit rules, by token
     */
    public function __construct(private readonly array $versions, private readonly array $types) {}

    /**
     * Every leave type that has a version, by token.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_map('strval', array_keys($this->versions));
    }

    /** The first day any rule applies, or null while none is approved. */
    public function startsOn(): ?string
    {
        $first = null;
        foreach ($this->versions as $versions) {
            if ($versions !== [] && ($first === null || $versions[0]['from'] < $first)) {
                $first = $versions[0]['from'];
            }
        }

        return $first;
    }

    public function activeOn(string $date): bool
    {
        $first = $this->startsOn();

        return $first !== null && $first <= $date;
    }

    /**
     * The version of a scope in force on `$date`: the latest whose
     * effective date is on or before it.
     *
     * @return array{id: string, from: string, params: array<string, mixed>}|null
     */
    public function version(string $scope, string $date): ?array
    {
        $found = null;
        foreach ($this->versions[$scope] ?? [] as $version) {
            if ($version['from'] > $date) {
                break;
            }
            $found = $version;
        }

        return $found;
    }

    /** A leave type's rule on `$date`; a type with no version in force has none (LeaveTypeRule::none()). */
    public function typeRule(string $token, string $date): LeaveTypeRule
    {
        $version = $this->version($token, $date);

        return $version === null ? LeaveTypeRule::none() : LeaveTypeRule::fromParams($version['params']);
    }

    /**
     * The leave types that must be ACQUIRED before they are used on
     * `$date`: those whose rule in force then has a waiting period.
     *
     * @return list<string>
     */
    public function gated(string $date): array
    {
        $out = [];
        foreach (array_keys($this->types) as $token) {
            if ($this->typeRule((string) $token, $date)->waitingMonths() !== null) {
                $out[] = (string) $token;
            }
        }

        return $out;
    }

    /**
     * A leave type's approved versions as the acquisition rule walks them,
     * oldest first, each ending the day before the next begins. Empty when
     * no version has a waiting period.
     *
     * @return list<array{id: string, from: string, to: ?string, months: ?int, extends: bool}>
     */
    public function waitingVersions(string $token): array
    {
        $out = [];
        $versions = $this->versions[$token] ?? [];
        $any = false;
        foreach ($versions as $index => $version) {
            $rule = LeaveTypeRule::fromParams($version['params']);
            $next = $versions[$index + 1]['from'] ?? null;
            $any = $any || $rule->waitingMonths() !== null;
            $out[] = [
                'id' => $version['id'],
                'from' => $version['from'],
                'to' => $next === null ? null : PayrollPeriod::addCalendarDays($next, -1),
                'months' => $rule->waitingMonths(),
                'extends' => $rule->extendsWait(),
            ];
        }

        return $any ? $out : [];
    }

    /**
     * The leave types any approved version gives a waiting period.
     *
     * @return list<string>
     */
    public function waitingTokens(): array
    {
        $out = [];
        foreach (array_keys($this->types) as $token) {
            if ($this->waitingVersions((string) $token) !== []) {
                $out[] = (string) $token;
            }
        }

        return $out;
    }

    /** One version's rule, by its id; null when the book does not hold it. */
    public function ruleOf(string $token, string $versionId): ?LeaveTypeRule
    {
        foreach ($this->versions[$token] ?? [] as $version) {
            if ($version['id'] === $versionId) {
                return LeaveTypeRule::fromParams($version['params']);
            }
        }

        return null;
    }

    /**
     * The one policy the rules give everybody on `$date`. A type earns only
     * while its credit rules say it earns AND its rule in force holds a
     * monthly amount. The rest has no setting of its own: a month's credits
     * post at the month end; approved leave without pay and absences both
     * count as days without pay, as they do for a waiting period; lateness,
     * undertime and absences are charged to the leave types marked as
     * chargeable, in their charge order — marking a type IS the switch —
     * each day's minutes converted on their own; and a cash-out is bounded
     * by the balance alone.
     */
    public function policyOn(string $date): LeavePolicy
    {
        $earning = [];
        foreach ($this->types as $token => $entry) {
            $rule = $entry['rules']->earnsCredits ? $this->typeRule((string) $token, $date)->earning() : null;
            if ($rule !== null) {
                $earning[(string) $token] = $rule;
            }
        }
        $late = $this->chargeable('late');
        $absence = $this->chargeable('absence');

        return LeavePolicy::fromParams([
            'posting_day' => LeavePolicy::MONTH_END,
            'unpaid_counts' => LeavePolicy::UNPAID_COUNTS,
            'earning' => $earning,
            'charges' => ['enabled' => $late !== [] || $absence !== [], 'combine' => 'DAY', 'late' => $late, 'absence' => $absence],
            'cash_out_max_per_year' => null,
        ]);
    }

    /** Whether the type's credit rules say it earns credits. */
    public function earns(string $token): bool
    {
        return isset($this->types[$token]) && $this->types[$token]['rules']->earnsCredits;
    }

    /** @return array<string, string> token => leave type name */
    public function names(): array
    {
        return array_map(static fn (array $entry): string => $entry['name'], $this->types);
    }

    /**
     * The types that may be charged for a kind, in charge order then name
     * order — LeaveTypeCreditRecords::chargeable, from the book's own copy.
     *
     * @return list<string>
     */
    private function chargeable(string $kind): array
    {
        $rows = [];
        foreach ($this->types as $token => $entry) {
            $rules = $entry['rules'];
            if (($kind === 'late' && $rules->chargeLate) || ($kind === 'absence' && $rules->chargeAbsence)) {
                $rows[] = ['token' => (string) $token, 'order' => $rules->chargeOrder, 'name' => $entry['name']];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);

        return array_column($rows, 'token');
    }
}
