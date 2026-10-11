<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Payroll\Application\Queries\IncentiveTypeRecords;
use App\Modules\Payroll\Contracts\Views\IncentiveTypeView as IncentiveType;
use App\Support\Access;
use App\Support\CodeSort;

/**
 * The Incentives & Bonuses rows as the table lists them: searched on id and
 * name, sorted by the chosen key, then reversed as a whole for descending
 * order. One copy, shared by the table and its CSV export.
 */
final class IncentiveTypeRows
{
    /** @return list<IncentiveType> */
    public static function list(Access $access, string $search, string $sortKey, string $sortDir): array
    {
        $needle = mb_strtolower(trim($search));
        $items = app(IncentiveTypeRecords::class)->list($access)
            ->filter(static fn (IncentiveType $r): bool => $needle === '' || str_contains(mb_strtolower("{$r->code} {$r->name}"), $needle))
            ->values()
            ->all();

        usort($items, static function (IncentiveType $a, IncentiveType $b) use ($sortKey): int {
            return match ($sortKey) {
                'name' => Hris::compareText($a->name, $b->name),
                // The Tax column, in the categories' order (taxable, exempt, de minimis, not income).
                'taxable' => (array_search(BenefitText::tax($a), array_keys(BenefitText::TAXES), true) <=> array_search(BenefitText::tax($b), array_keys(BenefitText::TAXES), true)) ?: Hris::compareText($a->name, $b->name),
                // The id column, so CodeSort — see App\Support\CodeSort.
                default => CodeSort::compare($a->code, $b->code),
            };
        });

        return $sortDir === 'desc' ? array_reverse($items) : $items;
    }
}
