<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Modules\Timekeeping\Contracts\Views\ShiftTypeView as ShiftType;
use App\Support\Access;
use App\Support\CodeSort;

/**
 * The Shift Type rows as the table lists them: searched on id and name,
 * sorted by the chosen key, then reversed as a whole for descending order.
 * One copy, shared by the table and its CSV export.
 */
final class ShiftTypeRows
{
    /** @return list<ShiftType> */
    public static function list(Access $access, string $search, string $sortKey, string $sortDir): array
    {
        $needle = strtolower(trim($search));

        $items = app(ReferenceData::class)->shiftTypes($access)
            ->filter(static function (ShiftType $r) use ($needle): bool {
                return $needle === '' || str_contains(strtolower("{$r->code} {$r->name}"), $needle);
            })
            ->values()
            ->all();

        usort($items, static function (ShiftType $a, ShiftType $b) use ($sortKey): int {
            return match ($sortKey) {
                // The id column, so CodeSort — see App\Support\CodeSort.
                'id' => CodeSort::compare($a->code, $b->code),
                'mode' => Hris::compareText($a->mode, $b->mode) ?: Hris::compareText($a->name, $b->name),
                default => Hris::compareText($a->name, $b->name),
            };
        });

        return $sortDir === 'desc' ? array_reverse($items) : $items;
    }
}
