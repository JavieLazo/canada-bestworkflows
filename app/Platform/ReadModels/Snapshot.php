<?php

declare(strict_types=1);

namespace App\Platform\ReadModels;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/** Materializes selected attributes only; relationships require explicit owner-supplied projections. */
final class Snapshot
{
    /** @template T of Record
     * @param  class-string<T>  $type
     * @param  array<string, mixed>  $relations
     * @return T
     */
    public static function of(Model $model, string $type, array $relations = []): Record
    {
        $serialized = $model->attributesToArray();
        $values = [];
        foreach (array_keys($serialized) as $key) {
            $values[$key] = $model->getAttribute($key);
        }

        self::ensureDetached($relations);
        self::ensureDetached($values);

        return new $type([...$values, ...$relations], $serialized);
    }

    private static function ensureDetached(mixed $value): void
    {
        if ($value instanceof Model || $value instanceof Builder || $value instanceof QueryBuilder) {
            throw new InvalidArgumentException('Read projections cannot contain persistence objects.');
        }
        if (is_iterable($value)) {
            foreach ($value as $child) {
                self::ensureDetached($child);
            }
        }
    }
}
