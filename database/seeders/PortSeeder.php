<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmployeeProfile;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Base for every seeder in the port of `src/lib/seed.ts`.
 *
 *  - upsert() resolves the stable seed identity, then the legacy primary key,
 *    then a NATURAL key, and writes through forceFill,
 *    so actor columns (reviewed_by, author_id, assigned_by, ...) and explicit
 *    created_at values can be seeded without widening any model's $fillable.
 *    Never upsert() the double-unique tables through Laravel's upsert() —
 *    MySQL ignores $uniqueBy (see the reference-list migration); this helper
 *    is one SELECT + save per row instead.
 *  - Fixture ids ('role-admin', 'emp-1') are stable SEED KEYS, never assigned
 *    to a new row. seed_identities remembers the real key that BaseModel
 *    generates. Legacy rows keep their existing primary keys and links.
 *  - at()/dateKey() mirror seed.ts at(daysAgo, hour, minute): Manila wall
 *    clock, a NEGATIVE daysAgo meaning the future, stored as UTC.
 */
abstract class PortSeeder extends Seeder
{
    /**
     * seed.ts EMPLOYEES order — index i here is the reference's emp-(i+1).
     *
     * Bare numbers: the visible codes employee_profiles.employee_code holds once
     * migration 2026_09_19_0002 has run. A seeder that said 'COP-00001'
     * would match nothing and insert a ninth employee on the next run.
     */
    protected const EMPLOYEE_CODES = ['1', '2', '3', '4', '5', '6', '7', '8'];

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $attributes
     */
    protected function upsert(string $model, array $match, array $attributes, bool $addOnly = false): Model
    {
        $seedKey = $match['id'] ?? $attributes['id'] ?? null;
        unset($match['id'], $attributes['id']);

        $prototype = new $model;

        // A row and its seed identity commit together. A failed save must not
        // leave a mapping to nothing, nor an unidentifiable generated row.
        return $prototype->getConnection()->transaction(function () use ($model, $prototype, $seedKey, $match, $attributes, $addOnly): Model {
            $id = is_string($seedKey) ? $this->seedId($model, $seedKey) : null;
            $row = $id === null ? null : $model::query()->find($id);
            $row ??= $match === [] ? null : $model::query()->where($match)->first();

            if ($row === null || ! $addOnly) {
                $row ??= new $model;
                $row->forceFill($match + $attributes);
                $row->save();
            }

            if (is_string($seedKey)) {
                $prototype->getConnection()->table('seed_identities')->updateOrInsert(
                    ['table_name' => $prototype->getTable(), 'seed_key' => $seedKey],
                    ['row_id' => (string) $row->getKey()],
                );
            }

            return $row;
        });
    }

    /**
     * Resolve a stable seed key without relying on an editable display name.
     * A legacy literal key is adopted on the next upsert. Deleted rows have
     * no identity to reuse: their replacement gets a fresh BaseModel key.
     *
     * @param  class-string<Model>  $model
     */
    protected function seedId(string $model, string $seedKey): ?string
    {
        $prototype = new $model;
        $id = $prototype->getConnection()->table('seed_identities')
            ->where('table_name', $prototype->getTable())
            ->where('seed_key', $seedKey)
            ->value('row_id');

        if (is_string($id) && $model::query()->whereKey($id)->exists()) {
            return $id;
        }

        return $model::query()->whereKey($seedKey)->exists() ? $seedKey : null;
    }

    /**
     * The `['code' => …]` match for a seeded row, tolerating a database
     * migration 2026_09_19_0002 has not reached.
     *
     * That migration makes visible codes bare numbers. Against an unconverted
     * copy — a restored backup or a host seeded before that migration ran — a
     * bare `'1'` would match
     * nothing and ProductionSeeder would insert a SECOND copy of every
     * reference row. So the legacy `POS-001` shape is accepted when, and only
     * when, a row still carries it.
     *
     * @param  class-string<Model>  $model
     * @return array<string, string>
     */
    protected function codeMatch(string $model, string $column, int $number, string $legacyPrefix, int $width = 3): array
    {
        if ($model === EmployeeProfile::class && $column === 'employee_code') {
            $id = $this->seedId(EmployeeProfile::class, 'emp-'.$number);
            $existing = $id === null ? null : EmployeeProfile::query()->whereKey($id)->value('employee_code');

            if (is_string($existing)) {
                return [$column => $existing];
            }

            foreach (self::employeeCodeVariants((string) $number) as $code) {
                if (EmployeeProfile::query()->where($column, $code)->exists()) {
                    return [$column => $code];
                }
            }

            return [$column => (string) $number];
        }

        $legacy = $legacyPrefix.'-'.str_pad((string) $number, $width, '0', STR_PAD_LEFT);

        return $model::query()->where($column, (string) $number)->exists()
            || ! $model::query()->where($column, $legacy)->exists()
                ? [$column => (string) $number]
                : [$column => $legacy];
    }

    /** Manila wall-clock instant $daysAgo back (negative = future), as UTC. */
    protected function at(int $daysAgo, int $hour, int $minute, int $second = 0): CarbonImmutable
    {
        return CarbonImmutable::now(ManilaClock::TZ)
            ->subDays($daysAgo)
            ->setTime($hour, $minute, $second)
            ->utc();
    }

    /** The Manila calendar day $daysAgo back (negative = future), as Y-m-d. */
    protected function dateKey(int $daysAgo): string
    {
        return CarbonImmutable::now(ManilaClock::TZ)->subDays($daysAgo)->format('Y-m-d');
    }

    /**
     * The seeded employees by reference index, resolved through the database
     * rather than assumed: on a DB where the codes already existed under other
     * ids, the real ids are what every foreign key must carry.
     *
     * @return array<int, string> 0-based reference index => employee_profiles.id
     */
    protected function employeeIdsByIndex(): array
    {
        // Imported BestWorkflows rows can retain BWF-00001, while an
        // unconverted EMS database holds COP-00001. An empty result is silent:
        // ShiftSeeder, AttendanceSeeder, LeaveSeeder and ProjectSeeder would
        // each seed nothing at all, with no error to explain it.
        $codes = [];
        foreach (self::EMPLOYEE_CODES as $code) {
            array_push($codes, ...self::employeeCodeVariants($code));
        }
        $byCode = EmployeeProfile::query()
            ->whereIn('employee_code', $codes)
            ->pluck('id', 'employee_code');

        $ids = [];

        foreach (self::EMPLOYEE_CODES as $i => $code) {
            $id = $this->seedId(EmployeeProfile::class, 'emp-'.($i + 1));
            foreach (self::employeeCodeVariants($code) as $variant) {
                $id ??= $byCode[$variant] ?? null;
            }

            if ($id !== null) {
                $ids[$i] = (string) $id;
            }
        }

        return $ids;
    }

    /** @return list<string> Existing employee-code spellings; never rewrite them. */
    private static function employeeCodeVariants(string $code): array
    {
        $padded = str_pad($code, 5, '0', STR_PAD_LEFT);

        return [$code, 'COP-'.$padded, 'BWF-'.$padded];
    }

    /** @return array<string, string> logical site key => actual work_sites id */
    protected function siteIdsBySeedKey(): array
    {
        $ids = [];

        foreach (['site-1', 'site-2', 'site-3'] as $key) {
            $id = $this->seedId(WorkSite::class, $key);
            if ($id !== null) {
                $ids[$key] = $id;
            }
        }

        return $ids;
    }

    protected function userId(string $email): ?int
    {
        $id = User::query()->where('email', $email)->value('id');

        return $id === null ? null : (int) $id;
    }
}
