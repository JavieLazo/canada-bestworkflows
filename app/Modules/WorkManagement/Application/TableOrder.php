<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Platform\ReadModels\SqlText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class TableOrder
{
    /** @template T of Model
     * @param  Builder<T>  $query
     * @param  non-empty-array<string, literal-string>  $columns
     * @param  list<string>  $text
     */
    public static function apply(Builder $query, array $columns, string $key, string $direction, array $text = []): void
    {
        $key = array_key_exists($key, $columns) ? $key : array_key_first($columns);
        if (in_array($key, $text, true)) {
            SqlText::order($query, $columns[$key], $direction);
        } else {
            $query->orderBy($columns[$key], $direction === 'desc' ? 'desc' : 'asc');
        }
    }
}
