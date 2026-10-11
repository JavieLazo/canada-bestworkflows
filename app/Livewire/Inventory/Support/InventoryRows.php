<?php

declare(strict_types=1);

namespace App\Livewire\Inventory\Support;

use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Modules\Inventory\Contracts\Views\ItemView;
use App\Modules\Inventory\Contracts\Views\ReceiptView;
use App\Support\Access;
use Illuminate\Pagination\LengthAwarePaginator;

/** Keeps table and CSV filter names and ordering on one presentation path. */
final class InventoryRows
{
    /** @param array<string,mixed> $query
     * @return LengthAwarePaginator<int, ItemView>
     */
    public static function items(Access $actor, array $query, int $page, int $perPage): LengthAwarePaginator
    {
        return app(InventoryRecords::class)->items($actor, self::filters($query), self::text($query, 'sort', 'code'), self::text($query, 'dir', 'asc'), $page, $perPage);
    }

    /** @param array<string,mixed> $query
     * @return LengthAwarePaginator<int, ReceiptView>
     */
    public static function receipts(Access $actor, array $query, int $page, int $perPage): LengthAwarePaginator
    {
        return app(InventoryRecords::class)->receipts($actor, self::filters($query), self::text($query, 'sort', 'code'), self::text($query, 'dir', 'desc'), $page, $perPage);
    }

    /** @param array<string,mixed> $query
     * @return array<string,string>
     */
    private static function filters(array $query): array
    {
        $filters = ['search' => self::text($query, 'q')];
        foreach (['status', 'category_id', 'location_id'] as $key) {
            $filters[$key] = self::text($query, $key, 'ALL');
        }

        return $filters;
    }

    /** @param array<string,mixed> $query */
    private static function text(array $query, string $key, string $default = ''): string
    {
        return isset($query[$key]) && is_string($query[$key]) ? $query[$key] : $default;
    }
}
