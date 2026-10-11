<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeAddress;
use App\Models\EmployeeChild;
use App\Models\EmployeeOtherItem;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Writes an employee's details row (`employee_pds_details`), address rows
 * (`employee_addresses`), children (`employee_children`) and Other info
 * lines (`employee_other_items`) beside the profile. An internal Workforce service,
 * like EmploymentHistoryRecorder: it takes no Access, because every caller is
 * a Workforce command that has already authorised the save.
 *
 * LOCK ORDER. Every call runs INSIDE the caller's transaction, AFTER the
 * caller has locked the `employee_profiles` row, after account eligibility
 * and any structure locks but BEFORE any ordinary consistent read:
 * profile row, then details row, then address rows. That lock
 * serialises every writer of one employee, so the rows below are read with
 * plain reads. NEVER `lockForUpdate()` a details or address row, and never
 * delete by employee and type: on InnoDB a locking read or a delete that
 * finds no row takes a gap lock on the unique index, and two first saves for
 * different employees then deadlock on their inserts. The writes are
 * therefore diff-based — insert, update in place or delete by primary key.
 * A list (the children, an Other info list) keeps line N on row N
 * (reconcile()), so its unique (employee, [kind,] position) never collides
 * and no range delete runs.
 *
 * MISSING TABLES AND COLUMNS. The code can run before its migrations (the
 * release extracts the files first and migrates later). A save that carries
 * only blank values for a missing table or details column then changes
 * nothing; one that carries a value is refused with a RuntimeException
 * (NOT_READY), which rolls the caller's whole transaction back. Table and
 * column probes are answered once per request (hasTable()).
 */
final class EmployeeDetailRows
{
    public const string NOT_READY = 'This part of the record can be saved once the system update finishes. Try again in a few minutes.';

    /**
     * Whether the details and address tables both exist — asked once per
     * request through hasTable(), never remembered longer, because a longer
     * memory would outlive the migration that creates them. A request in
     * flight while the cron migrates can at worst refuse a save with
     * NOT_READY.
     */
    public static function tablesReady(): bool
    {
        return self::hasTable('employee_pds_details') && self::hasTable('employee_addresses');
    }

    /**
     * Whether `$table` exists, asked once per request: `once()` keys its
     * memo on the closure's used variables, so each table has its own. A
     * test that creates or drops one of these tables and then runs app code
     * in the same test calls `Once::flush()` after its DDL.
     */
    public static function hasTable(string $table): bool
    {
        return once(static function () use ($table): bool {
            return Schema::hasTable($table);
        });
    }

    /**
     * The details table's columns, read once per request (see hasTable()):
     * a later migration adds columns to a table that already exists.
     *
     * @return list<string>
     */
    private static function detailColumns(): array
    {
        return once(static function (): array {
            return array_values(Schema::getColumnListing('employee_pds_details'));
        });
    }

    /**
     * Saves the given details columns, and only those: a Personal Details
     * save never touches UMID. A missing row reads as all NULL, so a save of
     * nothing but NULLs creates no row; any value, `false` and `true`
     * included, creates it.
     *
     * @param  array<string, mixed>  $pds
     */
    public function save(EmployeeProfile $employee, array $pds): void
    {
        if ($pds === []) {
            return;
        }

        $blank = array_filter($pds, static fn (mixed $value): bool => $value !== null) === [];

        if (! self::tablesReady()) {
            if ($blank) {
                return;
            }

            throw new RuntimeException(self::NOT_READY);
        }

        // A column a later migration adds may be missing yet: a NULL for it
        // is dropped, a value refused.
        $present = self::detailColumns();

        foreach ($pds as $column => $value) {
            if (! in_array($column, $present, true)) {
                if ($value !== null) {
                    throw new RuntimeException(self::NOT_READY);
                }

                unset($pds[$column]);
            }
        }

        if ($pds === []) {
            return;
        }

        // A plain read, serialised by the profile lock (see the class docblock).
        $row = EmployeePdsDetail::query()->where('employee_id', $employee->id)->first();

        if ($row === null) {
            if (! $blank) {
                EmployeePdsDetail::query()->create(['employee_id' => $employee->id] + $pds);
            }

            return;
        }

        $row->fill($pds)->save();
    }

    /**
     * Brings the address rows to `$rows`, type by type: a NULL type has no
     * row, any other is that row's seven parts. A type's row is created,
     * updated in place (Eloquent writes only the parts that changed) or
     * deleted by its primary key, so a save whose parts match the stored
     * rows sends no statement at all.
     *
     * @param  array{RESIDENTIAL: ?array<string, ?string>, PERMANENT: ?array<string, ?string>}  $rows
     */
    public function replaceAddresses(EmployeeProfile $employee, array $rows): void
    {
        if (! self::tablesReady()) {
            if ($rows['RESIDENTIAL'] === null && $rows['PERMANENT'] === null) {
                return;
            }

            throw new RuntimeException(self::NOT_READY);
        }

        $stored = EmployeeAddress::query()->where('employee_id', $employee->id)->get()->keyBy('type');

        foreach ([EmployeeAddress::RESIDENTIAL, EmployeeAddress::PERMANENT] as $type) {
            $parts = $rows[$type];
            $row = $stored->get($type);

            if ($parts === null) {
                $row?->delete();
            } elseif ($row === null) {
                EmployeeAddress::query()->create(['employee_id' => $employee->id, 'type' => $type] + $parts);
            } else {
                $row->fill($parts)->save();
            }
        }
    }

    /**
     * Brings the Other info lists to `$items`, kind => its lines in order
     * (NULL leaves that kind alone): line N stays on row N, updated in
     * place, and rows past the last line are deleted by primary key.
     *
     * @param  array<string, list<string>|null>  $items
     */
    public function replaceItems(EmployeeProfile $employee, array $items): void
    {
        if (! PdsLists::ready('other')) {
            foreach ($items as $lines) {
                if ($lines !== null && $lines !== []) {
                    throw new RuntimeException(self::NOT_READY);
                }
            }

            return;
        }

        // A plain read, serialised by the profile lock (see the class docblock).
        $stored = EmployeeOtherItem::query()->where('employee_id', $employee->id)->get()->groupBy('kind');

        foreach ($items as $kind => $lines) {
            if ($lines === null) {
                continue;
            }

            $this->reconcile(
                ($stored->get($kind) ?? collect())->keyBy('position'),
                $lines,
                static fn (Model $row, string $text): Model => $row->fill(['text' => $text]),
                static fn (int $position, string $text): Model => $employee->otherItems()->create(['kind' => $kind, 'position' => $position, 'text' => $text]),
            );
        }
    }

    /**
     * Brings the children's rows to `$children`, in sheet order: child N
     * stays on row N, updated in place, and rows past the last child are
     * deleted by primary key. The table always exists (it shipped before
     * the details tables).
     *
     * @param  list<array{name: string, birth_date: ?string}>  $children
     */
    public function replaceChildren(EmployeeProfile $employee, array $children): void
    {
        // A plain read, serialised by the profile lock (see the class docblock).
        $stored = EmployeeChild::query()->where('employee_id', $employee->id)->get()->keyBy('position');

        $this->reconcile(
            $stored,
            $children,
            static fn (Model $row, array $child): Model => $row->fill(['name' => $child['name'], 'birth_date' => $child['birth_date']]),
            static fn (int $position, array $child): Model => $employee->children()->create(['position' => $position, 'name' => $child['name'], 'birth_date' => $child['birth_date']]),
        );
    }

    /**
     * Brings stored rows keyed by position to `$wanted` (index = position),
     * over the union of both sets of positions: a position in both is
     * filled and saved (Eloquent sends nothing when nothing changed), one
     * only wanted is created, and every stored position past the last
     * wanted one — wherever it sits, a gap included — is deleted by its
     * primary key.
     *
     * @template TModel of Model
     *
     * @param  Collection<array-key, TModel>  $stored
     * @param  list<mixed>  $wanted
     */
    private function reconcile(Collection $stored, array $wanted, Closure $fill, Closure $create): void
    {
        $positions = array_unique([...array_map('intval', $stored->keys()->all()), ...array_keys($wanted)]);
        sort($positions);

        foreach ($positions as $position) {
            $row = $stored->get($position);

            if ($position >= count($wanted)) {
                $row?->delete();
            } elseif ($row === null) {
                $create($position, $wanted[$position]);
            } else {
                $fill($row, $wanted[$position])->save();
            }
        }
    }
}
