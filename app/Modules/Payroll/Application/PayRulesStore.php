<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayRules;
use App\Models\PayrollFormula;
use App\Models\PayrollPayRule;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * The Pay Basis & Rates as payroll reads them: which version prices a
 * period for an employee — the version of their Employment status in force
 * on the period's first day, else the company's — and whether the period
 * can be priced at all (a version changing inside it splits payrolls).
 *
 * While the table is absent (the window before the cron migrates) every
 * reader gets the reference's rules over the Formulas rows, exactly what
 * payroll computed before the table existed.
 */
final class PayRulesStore
{
    /** The scope of the company rule, which every status without an override of its own follows. */
    public const string COMPANY = PayrollPayRule::COMPANY;

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('payroll_pay_rules'));
    }

    /**
     * The version in force for a scope on the period's first day, as the
     * evidence records it: `scope`, `version_id` (null for the fallback),
     * `effective_from` and the figures. `error` says why the period cannot
     * be priced.
     *
     * @return array{entry: array{scope: string, version_id: ?string, effective_from: ?string, figures: array<string, mixed>}, error: ?string}
     */
    public function resolve(string $periodStart, string $periodEnd, ?string $statusId = null): array
    {
        if (! self::ready()) {
            return ['entry' => $this->fallback(), 'error' => null];
        }
        $scopes = $statusId === null ? [PayrollPayRule::COMPANY] : [$statusId, PayrollPayRule::COMPANY];
        $rows = PayrollPayRule::query()->whereIn('scope', $scopes)->orderBy('effective_from')->orderBy('id')->get()->groupBy('scope');

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
            if ($current === null) {
                // A status override that starts inside the period: the company
                // rule would price part of it and the override the rest.
                if ($next !== null && $next->effective_from->format('Y-m-d') <= $periodEnd && $scope !== PayrollPayRule::COMPANY) {
                    return ['entry' => $this->fallback(), 'error' => 'The Pay Basis & Rates change on '.PayrollPeriodText::day($next->effective_from->format('Y-m-d')).'. End this payroll before that date, or start it on that date.'];
                }

                continue;
            }
            $error = $next !== null && $next->effective_from->format('Y-m-d') <= $periodEnd
                ? 'The Pay Basis & Rates change on '.PayrollPeriodText::day($next->effective_from->format('Y-m-d')).'. End this payroll before that date, or start it on that date.'
                : null;

            return ['entry' => $this->entry($current), 'error' => $error];
        }

        return ['entry' => $this->fallback(), 'error' => 'The company has no Pay Basis & Rates version covering '.PayrollPeriodText::day($periodStart).'. Add one in Payroll Settings › Pay Basis & Rates.'];
    }

    /**
     * The cut-off check's verdict for a set of employees' statuses: the
     * first error found, naming the status it is about when the caller
     * knows its name.
     *
     * @param  list<?string>  $statusIds
     * @param  array<string, string>  $labels  status id => the Employment list name
     */
    public function cutoffError(string $periodStart, string $periodEnd, array $statusIds = [null], array $labels = []): ?string
    {
        return PayrollPeriod::cutoffError($periodStart, $periodEnd, function (string $start, string $end) use ($statusIds, $labels): ?string {
            foreach (array_unique([null, ...$statusIds], SORT_REGULAR) as $statusId) {
                $error = $this->resolve($start, $end, $statusId)['error'];
                if ($error === null) {
                    continue;
                }
                $label = $statusId === null ? null : ($labels[$statusId] ?? null);
                if ($label !== null && str_starts_with($error, 'The Pay Basis & Rates change on')) {
                    return str_replace(['The Pay Basis & Rates change on', 'or start it on that date.'], ["The Pay Basis & Rates for {$label} employees change on", 'start it on that date, or take those employees off it.'], $error);
                }

                return $statusId === null ? str_replace('The Pay Basis & Rates change on', "The company's Pay Basis & Rates change on", $error) : $error;
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
        return ['scope' => $row->scope, 'version_id' => (string) $row->id, 'effective_from' => $row->effective_from->format('Y-m-d'), 'figures' => $row->params];
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
