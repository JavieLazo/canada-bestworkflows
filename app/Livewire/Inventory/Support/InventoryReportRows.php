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
}
