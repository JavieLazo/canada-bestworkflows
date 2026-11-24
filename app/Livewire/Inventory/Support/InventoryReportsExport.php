<?php

declare(strict_types=1);

namespace App\Livewire\Inventory\Support;

use App\Modules\Inventory\Application\Queries\InventoryReports;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;

/**
 * Exports the same report selection through bounded owner-query pages. A
 * report with a Cost column ends in ONE total row — "Total", how many items,
 * and their summed cost under Cost — read from the same owner query and
 * filters as the rows above it; the movement history has none.
 */
final class InventoryReportsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        InventoryReportRows::arrange($actor, $query, 1, 1);
        $columns = app(InventoryReports::class)->columns($query['report'] ?? 'holdings');

        return new CsvExport('inventory-report.csv', $columns, static function () use ($actor, $query, $columns): iterable {
            foreach (CsvExport::pages(static fn (int $page, int $size) => InventoryReportRows::arrange($actor, $query, $page, $size)) as $row) {
                yield $row->cells;
            }
            $total = self::totalRow($columns, InventoryReportRows::value($actor, $query));
            if ($total !== null) {
                yield $total;
            }
        });
    }

    /**
     * The closing row: blank under every column but the first ("Total"), the
     * second (the item count, and how many have no cost recorded) and Cost.
     *
     * @param  list<string>  $columns
     * @param  array{items:int,value:float,without_cost:int,categories:list<array{name:string,items:int,value:float,without_cost:int}>}|null  $value
     * @return list<string>|null
     */
    private static function totalRow(array $columns, ?array $value): ?array
    {
        $cost = array_search('Cost', $columns, true);
        if ($value === null || ! is_int($cost)) {
            return null;
        }
        $note = InventoryReportRows::withoutCostNote($value['without_cost']);
        $row = [];
        foreach (array_keys($columns) as $index) {
            $row[] = match ($index) {
                0 => 'Total',
                1 => number_format($value['items']).($value['items'] === 1 ? ' item' : ' items').($note === '' ? '' : '; '.$note),
                $cost => number_format($value['value'], 2, '.', ''),
                default => '',
            };
        }

        return $row;
    }
}
