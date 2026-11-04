<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Modules\Payroll\Contracts\Views\PayrollRunView as PayrollRun;
use App\Support\CodeSort;
use Illuminate\Support\Collection;

/**
 * The payroll runs list: the filter pipeline and sort of
 * payroll-page.tsx:53-76 over the runs PayrollRecords::list() returned —
 * comparator, then a whole-list reverse for descending, exactly as the
 * source did it. One copy, shared by the table and its CSV export.
 */
final class PayrollRunRows
{
    /**
     * @param  Collection<int, PayrollRun>  $runs
     * @return Collection<int, PayrollRun>
     */
    public static function arrange(Collection $runs, string $search, string $status, string $sortKey, string $sortDir): Collection
    {
        $needle = mb_strtolower(trim($search));

        $visible = $runs->filter(static function (PayrollRun $r) use ($needle, $status): bool {
            if ($status !== 'ALL' && $r->status !== $status) {
                return false;
            }

            return $needle === ''
                || str_contains(mb_strtolower("{$r->code} {$r->name}"), $needle);
        })->values();

        $sorted = $visible->sort(static function (PayrollRun $a, PayrollRun $b) use ($sortKey): int {
            return match ($sortKey) {
                // CodeSort, not compareText: the run id is a bare number, and
                // text order would put payroll 10 between 1 and 2.
                'id' => CodeSort::compare($a->code, $b->code),
                'employees' => $a->members_count <=> $b->members_count,
                'to' => PayrollData::compareText($a->period_end->format('Y-m-d'), $b->period_end->format('Y-m-d')),
                'pay' => PayrollData::compareText($a->pay_date?->format('Y-m-d') ?? '', $b->pay_date?->format('Y-m-d') ?? ''),
                'status' => PayrollData::compareText($a->status, $b->status),
                'name' => PayrollData::compareText($a->name, $b->name),
                default => PayrollData::compareText($a->period_start->format('Y-m-d'), $b->period_start->format('Y-m-d')),
            };
        })->values();

        return $sortDir === 'desc' ? $sorted->reverse()->values() : $sorted;
    }
}
