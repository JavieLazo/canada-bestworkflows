<?php

declare(strict_types=1);

namespace App\Livewire\Inventory\Support;

use App\Modules\Inventory\Application\Queries\InventoryReports;
use App\Modules\Inventory\Contracts\Views\ReportRow;
use App\Support\Access;
use Illuminate\Pagination\LengthAwarePaginator;

/** Keeps screen and download filters on the same report query. */
final class InventoryReportRows
{
    /** @param array<string,string> $filters
     * @return LengthAwarePaginator<int, ReportRow>
     */
    public static function arrange(Access $actor, array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return app(InventoryReports::class)->rows($actor, $filters['report'] ?? 'holdings', $filters['q'] ?? '', $filters['employee'] ?? '', $page, $perPage);
    }

    /**
     * What those same rows add up to, read with the same filters: null for
     * the movement history, which has no Cost column.
     *
     * @param  array<string,string>  $filters
     * @return array{items:int,value:float,without_cost:int,categories:list<array{name:string,items:int,value:float,without_cost:int}>}|null
     */
    public static function value(Access $actor, array $filters): ?array
    {
        return app(InventoryReports::class)->value($actor, $filters['report'] ?? 'holdings', $filters['q'] ?? '', $filters['employee'] ?? '');
    }

    /**
     * "1 item has no cost recorded" / "3 items have no cost recorded", or
     * blank when every item has one — the sentence the screen and the CSV's
     * total row share.
     */
    public static function withoutCostNote(int $count): string
    {
        return match (true) {
            $count <= 0 => '',
            $count === 1 => '1 item has no cost recorded',
            default => number_format($count).' items have no cost recorded',
        };
    }
}
