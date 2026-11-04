<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayRules;
use App\Models\PayrollFormula;
use App\Models\PayrollPayRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * The Pay Basis & Rates as payroll reads them: which version prices a
 * period for an employee — a version of their own in force on the period's
 * first day, else the version of their Employment status, else the
 * company's — and whether the period can be priced at all (a version
 * changing inside it splits payrolls).
 *
 * An employee's own versions include "follows" ones (no figures): from that
 * date the employee is priced by their status's or the company's rules
 * again, which is the only way an employee's own rates end, because an
 * approved version is never deleted. Only APPROVED versions price anyone:
 * an employee's draft waits for approval.
 *
 * While the table is absent (the window before the cron migrates) every
 * reader gets the reference's rules over the Formulas rows, exactly what
 * payroll computed before the table existed; while `scope_type` is absent
 * nobody has rates of their own.
 */
final class PayRulesStore
{
    /** The scope of the company rule, which every status without an override of its own follows. */
    public const string COMPANY = PayrollPayRule::COMPANY;

    /** A version's `status` as the views carry it: a draft waits for approval and prices nobody. */
    public const string DRAFT = PayrollPayRule::DRAFT;

    public const string APPROVED = PayrollPayRule::APPROVED;

    private const string CHANGE = 'The Pay Basis & Rates change on';

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('payroll_pay_rules'));
    }

    /** Whether a version can be one employee's (2026_10_30_0001 has run). */
    public static function employeeScopesReady(): bool
    {
        return once(static fn (): bool => self::ready() && Schema::hasColumn('payroll_pay_rules', 'scope_type'));
    }

    /** Whether versions carry an approval (2026_11_02_0002 has run): until then every row is in force. */
    public static function approvalReady(): bool
    {
        return once(static fn (): bool => self::employeeScopesReady() && Schema::hasColumn('payroll_pay_rules', 'status'));
    }

    /**
     * The scope's versions that price anyone: the approved ones. A draft
     * waits for approval and is never in force.
     *
     * @return Builder<PayrollPayRule>
     */
    public static function approved(): Builder
    {
        return PayrollPayRule::query()->when(self::approvalReady(), static fn ($query) => $query->where('status', PayrollPayRule::APPROVED));
    }

    /**
     * The version in force for an employee on the period's first day, as
     * the evidence records it: `scope`, `version_id` (null for the
     * fallback), `effective_from` and the figures. `error` says why the
     * period cannot be priced. With no employee, their status's or the
     * company's; with no status either, the company's.
     *
     * @return array{entry: array{scope: string, version_id: ?string, effective_from: ?string, figures: array<string, mixed>}, error: ?string}
     */
    public function resolve(string $periodStart, string $periodEnd, ?string $statusId = null, ?string $employeeId = null): array
    {
        if (! self::ready()) {
            return ['entry' => $this->fallback(), 'error' => null];
        }
        if (! self::employeeScopesReady()) {
            $employeeId = null;
        }
        $scopes = array_values(array_filter([$employeeId, $statusId, PayrollPayRule::COMPANY], static fn (?string $scope): bool => $scope !== null));
        $rows = self::approved()->whereIn('scope', $scopes)->orderBy('effective_from')->orderBy('id')->get()->groupBy('scope');

        foreach ($scopes as $scope) {
            $versions = $rows->get($scope);
            if ($versions === null) {
                continue;
            }
            $current = null;
            $next = null;
            foreach ($versions as $row) {
                $from = $row->effective_from->format('Y-m-d');
                if ($from <= $periodStart) {
                    $current = $row;
                } elseif ($next === null) {
                    $next = $row;
                }
            }
            $change = $next !== null && $next->effective_from->format('Y-m-d') <= $periodEnd
                ? self::CHANGE.' '.PayrollPeriodText::day($next->effective_from->format('Y-m-d')).'. End this payroll before that date, or start it on that date.'
                : null;
            if ($scope === $employeeId) {
                // The employee's own: any version starting inside the period
                // splits it; a "follows" version in force hands them on.
                if ($change !== null) {
                    return ['entry' => $this->fallback(), 'error' => $change];
                }
                if ($current === null || $current->params === null) {
                    continue;
                }

                return ['entry' => $this->entry($current), 'error' => null];
            }
            if ($current === null) {
                // A status override that starts inside the period: the company
                // rule would price part of it and the override the rest.
                if ($change !== null && $scope !== PayrollPayRule::COMPANY) {
                    return ['entry' => $this->fallback(), 'error' => $change];
                }

                continue;
            }

            return ['entry' => $this->entry($current), 'error' => $change];
        }

        return ['entry' => $this->fallback(), 'error' => 'The company has no Pay Basis & Rates version covering '.PayrollPeriodText::day($periodStart).'. Add one in Payroll Settings › Pay Basis & Rates.'];
    }

    /**
     * The versions in force over a period, day by day (DIVERGENCE 17): the
     * entry in force on the first day, then one segment per later date a
     * different version takes over — a version of the employee's own, of
     * their status or of the company starting inside the period, or a
     * "follows" row handing the employee back. `error` is a coverage
     * refusal only: no version at all on some day of the period.
     *
     * @return array{entry: array{scope: string, version_id: ?string, effective_from: ?string, figures: array<string, mixed>}, segments: list<array{from: string, scope: string, version_id: ?string, effective_from: ?string, figures: array<string, mixed>}>, error: ?string}
     */
    public function timeline(string $periodStart, string $periodEnd, ?string $statusId = null, ?string $employeeId = null): array
    {
        $first = $this->resolve($periodStart, $periodStart, $statusId, $employeeId);
        if ($first['error'] !== null) {
            return ['entry' => $first['entry'], 'segments' => [], 'error' => $first['error']];
        }
        $segments = [];
        $previous = $first['entry'];
        foreach ($this->changeDates($periodStart, $periodEnd, $statusId, $employeeId) as $date) {
            $next = $this->resolve($date, $date, $statusId, $employeeId);
            if ($next['error'] !== null) {
                return ['entry' => $first['entry'], 'segments' => [], 'error' => $next['error']];
            }
            if ($next['entry']['version_id'] === $previous['version_id'] && $next['entry']['scope'] === $previous['scope']) {
                continue;
            }
            $segments[] = ['from' => $date, ...$next['entry']];
            $previous = $next['entry'];
        }

        return ['entry' => $first['entry'], 'segments' => $segments, 'error' => null];
    }

    /**
     * The dates inside a period, after its first day, on which any version
     * that could price the employee starts.
     *
     * @return list<string>
     */
    private function changeDates(string $periodStart, string $periodEnd, ?string $statusId, ?string $employeeId): array
    {
        if (! self::ready()) {
            return [];
        }
        if (! self::employeeScopesReady()) {
            $employeeId = null;
        }
        $scopes = array_values(array_filter([$employeeId, $statusId, PayrollPayRule::COMPANY], static fn (?string $scope): bool => $scope !== null));
        $dates = self::approved()->whereIn('scope', $scopes)
            ->whereDate('effective_from', '>', $periodStart)
            ->whereDate('effective_from', '<=', $periodEnd)
            ->orderBy('effective_from')
            ->pluck('effective_from')
            ->map(static fn ($from): string => $from->format('Y-m-d'))
            ->unique()
            ->values()
            ->all();

        return array_values(array_map('strval', $dates));
    }

    /**
     * Which of these employees have versions of their own: only they are
     * resolved one by one, everyone else by their status.
     *
     * @param  list<string>  $employeeIds
     * @return list<string>
     */
    public function employeesWithOwnRules(array $employeeIds): array
    {
        if ($employeeIds === [] || ! self::employeeScopesReady()) {
            return [];
        }

        return array_values(array_map('strval', self::approved()->where('scope_type', PayrollPayRule::SCOPE_EMPLOYEE)
            ->whereIn('scope', $employeeIds)->distinct()->pluck('scope')->all()));
    }

    /**
     * The cut-off check's verdict for a set of employees' statuses, and for
     * the employees with rates of their own: the first COVERAGE error found
     * (no version on some day of the period). A version changing inside the
     * period is no refusal any more (DIVERGENCE 17): each day is priced by
     * the version in force on it.
     *
     * @param  list<?string>  $statusIds
     * @param  array<string, string>  $labels  status id => the Employment list name (kept for the callers' signature)
     * @param  array<string, array{status_id: ?string, name: string}>  $employees  employee id => their status and name, for those with rates of their own
     */
    public function cutoffError(string $periodStart, string $periodEnd, array $statusIds = [null], array $labels = [], array $employees = []): ?string
    {
        return PayrollPeriod::cutoffError($periodStart, $periodEnd, function (string $start, string $end) use ($statusIds, $employees): ?string {
            foreach (array_unique([null, ...$statusIds], SORT_REGULAR) as $statusId) {
                $error = $this->timeline($start, $end, $statusId)['error'];
                if ($error !== null) {
                    return $error;
                }
            }
            foreach ($employees as $employeeId => $employee) {
                $error = $this->timeline($start, $end, $employee['status_id'], (string) $employeeId)['error'];
                if ($error !== null) {
                    return $error;
                }
            }

            return null;
        });
    }

    /**
     * The rules an evidence entry describes, strictly.
     *
     * @param  array{scope: string, version_id: ?string, effective_from: ?string, figures: array<string, mixed>}  $entry
     * @param  list<mixed>  $formulas  the captured Formulas rows, for an entry with no version
     */
    public static function rulesFor(array $entry, array $formulas): PayRules
    {
        if ($entry['version_id'] === null) {
            return PayRules::fromFormulas($formulas);
        }
        try {
            return PayRules::fromArray($entry['figures']);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('A Pay Basis & Rates version holds figures payroll cannot read: '.$e->getMessage());
        }
    }

    /**
     * @return array{scope: string, version_id: string, effective_from: string, figures: array<string, mixed>}
     */
    private function entry(PayrollPayRule $row): array
    {
        return ['scope' => $row->scope, 'version_id' => (string) $row->id, 'effective_from' => $row->effective_from->format('Y-m-d'), 'figures' => $row->params ?? []];
    }

    /**
     * The reference's rules over the Formulas rows as they stand.
     *
     * @return array{scope: string, version_id: null, effective_from: null, figures: array<string, mixed>}
     */
    private function fallback(): array
    {
        $formulas = Schema::hasTable('payroll_formulas') ? array_values(PayrollFormula::query()->get()->map->toArray()->all()) : [];

        return ['scope' => PayrollPayRule::COMPANY, 'version_id' => null, 'effective_from' => null, 'figures' => PayRules::fromFormulas($formulas)->toArray()];
    }
}
