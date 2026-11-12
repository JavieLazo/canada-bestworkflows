<?php

declare(strict_types=1);

namespace App\Platform\ReadModels;

use App\Support\CodeSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Pdo\Sqlite;

/** Preserves TextSort's ASCII byte folding and blank ordering during SQL pagination. */
final class SqlText
{
    /**
     * The SQL twin of a PHP join of trimmed words — `EmployeeName::display`'s
     * `trim(implode(' ', array_filter(trimmed parts)))`: each column trimmed as
     * PHP trims, the blank ones left out, the rest joined by single spaces, ''
     * when every one is blank. A search over a composed name then finds
     * exactly the phrases the PHP spelling holds, one across the name's
     * boundary included.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  list<literal-string>  $columns
     * @return literal-string
     */
    public static function joinedWords(Builder $query, array $columns): string
    {
        if ($query->getModel()->getConnection()->getDriverName() === 'sqlite') {
            self::sqliteFunctions($query);
            $parts = array_map(static fn (string $column): string => "CASE WHEN ems_trim({$column}) = '' THEN '' ELSE ' ' || ems_trim({$column}) END", $columns);

            // Every word carries its leading space; the first one's goes.
            return 'SUBSTR('.implode(' || ', $parts).', 2)';
        }

        $space = '\\\\x00\\\\x09\\\\x0A\\\\x0B\\\\x0D\\\\x20';
        $parts = array_map(static fn (string $column): string => "NULLIF(REGEXP_REPLACE(COALESCE({$column}, ''), '^[{$space}]+|[{$space}]+$', ''), '')", $columns);

        // CONCAT_WS skips a NULL argument, so a blank word adds no space.
        return "CONCAT_WS(' ', ".implode(', ', $parts).')';
    }

    /**
     * `column IN (values)` compared byte for byte, as PHP's `===` compares:
     * MariaDB's case-insensitive, pad-space collations would also match
     * "driver" or "Driver " for "Driver". SQLite's `=` is already binary.
     * An empty list matches nobody.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  literal-string  $column
     * @param  list<string>  $values
     */
    public static function whereExactIn(Builder $query, string $column, array $values): void
    {
        if ($values === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($query->getModel()->getConnection()->getDriverName() === 'sqlite') {
            $query->whereIn($column, $values);

            return;
        }

        $query->whereRaw('CAST('.$column.' AS BINARY) IN ('.implode(', ', array_fill(0, count($values), 'CAST(? AS BINARY)')).')', $values);
    }

    /**
     * A column's distinct non-null values as PHP tells strings apart, byte
     * for byte — MariaDB's DISTINCT would fold "Driver" and "driver" into one,
     * and a filter matched with {@see whereExactIn()} must offer both. The
     * caller's query carries the row scope.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  literal-string  $column
     * @return list<string>
     */
    public static function distinctValues(Builder $query, string $column): array
    {
        $value = $query->getModel()->getConnection()->getDriverName() === 'sqlite' ? $column : 'CAST('.$column.' AS BINARY)';
        $values = [];

        // A copy: the caller's builder keeps its own columns and conditions.
        foreach ((clone $query)->toBase()->whereNotNull($column)->selectRaw('DISTINCT '.$value.' AS ems_value')->pluck('ems_value') as $row) {
            $values[] = (string) $row;
        }

        return $values;
    }

    /** @template T of Model
     * @param  Builder<T>  $query
     * @param  literal-string  $expression
     * @param  list<int|string>  $bindings
     */
    public static function order(Builder $query, string $expression, string $direction, array $bindings = []): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $sqlite = $query->getModel()->getConnection()->getDriverName() === 'sqlite';
        if ($sqlite) {
            self::sqliteFunctions($query);
            $trimmed = 'ems_trim('.$expression.')';
            $folded = 'CAST(LOWER('.$trimmed.') AS BLOB)';
            $bytes = 'CAST('.$trimmed.' AS BLOB)';
        } else {
            // PHP trim excludes form-feed; a generic SQL whitespace class does not.
            $space = '\\\\x00\\\\x09\\\\x0A\\\\x0B\\\\x0D\\\\x20';
            $trimmed = "REGEXP_REPLACE(COALESCE({$expression}, ''), '^[{$space}]+|[{$space}]+$', '')";
            $bytes = 'CAST('.$trimmed.' AS BINARY)';
            $folded = $bytes;
            foreach (range('A', 'Z') as $letter) {
                $folded = "REPLACE({$folded}, '{$letter}', '".strtolower($letter)."')";
            }
        }
        $query->orderByRaw("CASE WHEN LENGTH({$trimmed}) = 0 THEN 1 ELSE 0 END {$direction}", $bindings)
            ->orderByRaw($folded.' '.$direction, $bindings)->orderByRaw($bytes.' '.$direction, $bindings);
    }

    /**
     * The SQL twin of {@see CodeSort::compare} — for the ELEVEN minted id
     * columns that hold bare numbers. NOT for `projects.code` or
     * `project_tasks.code`: those carry `PRJ-001`/`TSK-014` and their
     * padding, so they order through {@see self::order()}.
     *
     * `+ 0` rather than a CAST: MariaDB and SQLite spell their cast types
     * differently (UNSIGNED vs INTEGER) but both coerce a numeric string with
     * the same arithmetic, and a non-numeric leftover coerces to 0 on both.
     * Three keys, in the order CodeSort uses: blanks last, then the number,
     * then the raw text — the third is what keeps two values that share a
     * number ('7' and '007') and two leftovers that both coerce to 0 in a
     * defined order instead of the engine's whim.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  literal-string  $expression
     * @param  list<int|string>  $bindings
     */
    public static function orderNumeric(Builder $query, string $expression, string $direction, array $bindings = []): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $value = "COALESCE({$expression}, '')";

        // Three ranks, the same three CodeSort uses: numbers, then whatever is
        // not a number (an id that carries a prefix, a hand-entered value),
        // then blanks. The rank cannot come from `+ 0` alone —
        // 'PRJ-LEGACY' + 0 is 0, which would file it in front of id 2 while
        // the PHP comparator files it last.
        $digits = $query->getModel()->getConnection()->getDriverName() === 'sqlite'
            ? "({$value} <> '' AND {$value} NOT GLOB '*[^0-9]*')"
            : "({$value} REGEXP '^[0-9]+$')";

        $query->orderByRaw("CASE WHEN LENGTH({$value}) = 0 THEN 2 WHEN {$digits} THEN 0 ELSE 1 END {$direction}", $bindings)
            // `+ 0` rather than a CAST: MariaDB and SQLite spell their cast
            // types differently (UNSIGNED vs INTEGER) but both coerce a
            // numeric string with the same arithmetic. Only the numbers get
            // this key: '7A' coerces to 7 and 'B1' to 0, which would order two
            // leftovers by their leading digits while CodeSort orders them as
            // text.
            ->orderByRaw("CASE WHEN {$digits} THEN ({$value}) + 0 ELSE 0 END {$direction}", $bindings);

        // The leftovers order among themselves exactly as TextSort orders
        // them, which is what keeps this in step with CodeSort's own fallback.
        self::order($query, $expression, $direction, $bindings);
    }

    /** @template T of Model
     * @param  Builder<T>  $query
     * @param  list<literal-string>  $expressions
     */
    public static function contains(Builder $query, array $expressions, string $search): void
    {
        $needle = mb_strtolower(trim($search));
        if ($needle === '') {
            return;
        }
        $parts = [];
        foreach ($expressions as $expression) {
            $parts[] = "COALESCE({$expression}, '')";
        }
        if ($query->getModel()->getConnection()->getDriverName() === 'sqlite') {
            self::sqliteFunctions($query);
            $query->whereRaw('INSTR(ems_lower('.implode(" || ' ' || ", $parts).'), ?) > 0', [$needle]);
        } else {
            $text = "CONCAT_WS(' ', ".implode(', ', $parts).')';
            $unicode = 'LENGTH('.$text.') <> CHAR_LENGTH('.$text.')';
            $key = $query->getModel()->getQualifiedKeyName();
            $matches = [];
            $hasUnicode = false;
            // PHP and MariaDB differ on Unicode casing (for example dotted I
            // and final sigma). Inspect only those narrow strings, in batches,
            // before pagination; never hydrate every task/project projection.
            $candidates = (clone $query)->toBase()->reorder()->select($key.' AS ems_id')
                ->selectRaw($text.' AS ems_text')->whereRaw($unicode)->lazyById(500, $key, 'ems_id');
            foreach ($candidates as $row) {
                $hasUnicode = true;
                if (is_string($row->ems_text) && str_contains(mb_strtolower($row->ems_text), $needle)) {
                    $matches[] = $row->ems_id;
                }
            }
            $predicate = 'LOCATE(CAST(? AS BINARY), CAST(LOWER('.$text.') AS BINARY)) > 0';
            $query->where(static function (Builder $q) use ($hasUnicode, $unicode, $predicate, $needle, $key, $matches): void {
                $q->where(static function (Builder $ascii) use ($hasUnicode, $unicode, $predicate, $needle): void {
                    if ($hasUnicode) {
                        $ascii->whereRaw('NOT ('.$unicode.')');
                    }
                    $ascii->whereRaw($predicate, [$needle]);
                });
                if ($matches !== []) {
                    // One bound JSON value avoids the prepared-statement
                    // placeholder ceiling for a broad Unicode search.
                    $q->orWhereIn($key, static fn (\Illuminate\Database\Query\Builder $ids) => $ids
                        ->selectRaw('CAST(ems_matching_ids.id AS BINARY)')->fromRaw("JSON_TABLE(?, '$[*]' COLUMNS(id VARCHAR(26) PATH '$')) AS ems_matching_ids", [json_encode($matches, JSON_THROW_ON_ERROR)]));
                }
            });
        }
    }

    /** @template T of Model
     * @param  Builder<T>  $query
     * @param  literal-string  $column
     * @param  array<string, int|string>  $values
     * @param  literal-string  $fallback
     */
    public static function orderMapped(Builder $query, string $column, array $values, string $fallback, string $direction, bool $text = true): void
    {
        $expression = 'CASE '.$column;
        $bindings = [];
        foreach ($values as $key => $value) {
            $expression .= ' WHEN ? THEN ?';
            array_push($bindings, $key, $value);
        }
        $expression .= ' ELSE '.$fallback.' END';
        if ($text) {
            self::order($query, $expression, $direction, $bindings);
        } else {
            $query->orderByRaw($expression.($direction === 'desc' ? ' DESC' : ' ASC'), $bindings);
        }
    }

    /** @template T of Model
     * @param  Builder<T>  $query
     */
    private static function sqliteFunctions(Builder $query): void
    {
        $pdo = $query->getModel()->getConnection()->getPdo();
        if ($pdo instanceof Sqlite) {
            $pdo->createFunction('ems_trim', static fn (?string $value): string => trim((string) $value), 1);
            $pdo->createFunction('ems_lower', static fn (?string $value): string => mb_strtolower((string) $value), 1);
        }
    }
}
