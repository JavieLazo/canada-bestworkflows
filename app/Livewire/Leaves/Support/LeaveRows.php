<?php

declare(strict_types=1);

namespace App\Livewire\Leaves\Support;

use App\Livewire\Leaves\LeaveLabels;
use App\Modules\Timekeeping\Contracts\Views\LeaveTravelRequestView as LeaveTravelRequest;
use App\Support\CodeSort;
use App\Support\EmployeeName;
use Illuminate\Support\Collection;

/**
 * The Leave Management request rows: the filter pipeline and the sort of
 * leaves-page.tsx:79-107 over the scoped list — comparator, then a
 * whole-list reverse() for descending, exactly as the source did it. One
 * copy, shared by the table and its CSV export.
 */
final class LeaveRows
{
    /**
     * @param  Collection<int, LeaveTravelRequest>  $rows
     * @param  array<string, string>  $described  row id => resolved description
     * @return Collection<int, LeaveTravelRequest>
     */
    public static function arrange(Collection $rows, array $described, string $search, string $status, string $description, string $dayType, string $sortKey, string $sortDir): Collection
    {
        $needle = mb_strtolower(trim($search));

        $visible = $rows->filter(static function (LeaveTravelRequest $r) use ($described, $needle, $status, $description, $dayType): bool {
            if ($status !== 'ALL' && $r->status !== $status) {
                return false;
            }

            if ($description !== 'ALL' && $described[$r->id] !== $description) {
                return false;
            }

            if ($dayType !== 'ALL' && ($r->day_type ?? 'WHOLE_DAY') !== $dayType) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            $name = EmployeeName::display($r->employee);

            return str_contains(mb_strtolower("{$r->code} {$name} {$described[$r->id]}"), $needle);
        })->values();

        $sorted = $visible->sort(static function (LeaveTravelRequest $a, LeaveTravelRequest $b) use ($described, $sortKey): int {
            return match ($sortKey) {
                'employee' => LeaveLabels::compareText($a->employee?->last_name, $b->employee?->last_name),
                // When the request was filed: the stored instant, to the
                // millisecond; the id settles a tie, so the order is stable.
                'filed' => ($a->created_at->getTimestampMs() <=> $b->created_at->getTimestampMs()) ?: CodeSort::compare($a->code, $b->code),
                'date' => LeaveLabels::compareText($a->start_date->format('Y-m-d'), $b->start_date->format('Y-m-d'))
                    ?: LeaveLabels::compareText($a->end_date->format('Y-m-d'), $b->end_date->format('Y-m-d')),
                'description' => LeaveLabels::compareText($described[$a->id], $described[$b->id]),
                'dayType' => LeaveLabels::compareText(
                    LeaveLabels::dayTypeLabel($a->day_type),
                    LeaveLabels::dayTypeLabel($b->day_type),
                ),
                // The id column, so CodeSort — bare numbers do not sort as text.
                default => CodeSort::compare($a->code, $b->code),
            };
        })->values();

        return $sortDir === 'desc' ? $sorted->reverse()->values() : $sorted;
    }
}
