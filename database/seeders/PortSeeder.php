<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmployeeProfile;
use App\Models\User;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Base for every seeder in the port of `src/lib/seed.ts`.
 *
 *  - upsert() matches on the row's NATURAL key and writes through forceFill,
 *    so actor columns (reviewed_by, author_id, assigned_by, ...) and explicit
 *    created_at values can be seeded without widening any model's $fillable.
 *    Never upsert() the double-unique tables through Laravel's upsert() —
 *    MySQL ignores $uniqueBy (see the reference-list migration); this helper
 *    is one SELECT + save per row instead.
 *  - Literal ids ('role-admin', 'emp-1') are sanctioned by BaseModel: "The
 *    production seed supplies literal ids, exactly as the TypeScript seed
 *    did." An EXISTING row always keeps its id — foreign keys already point
 *    at it.
 *  - at()/dateKey() mirror seed.ts at(daysAgo, hour, minute): Manila wall
 *    clock, a NEGATIVE daysAgo meaning the future, stored as UTC.
 */
abstract class PortSeeder extends Seeder
{
    /** seed.ts EMPLOYEES order — index i here is the reference's emp-(i+1). */
    protected const EMPLOYEE_CODES = [
        'BWF-00001',
        'BWF-00002',
        'BWF-00003',
        'BWF-00004',
        'BWF-00005',
        'BWF-00006',
        'BWF-00007',
        'BWF-00008',
    ];

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $attributes
     */
    protected function upsert(string $model, array $match, array $attributes): Model
    {
        /** @var Model|null $row */
        $row = $model::query()->where($match)->first();

        if ($row !== null) {
            // Never rewrite an existing primary key: rows created before this
            // run keep the id their foreign keys already point at.
            unset($attributes['id']);
        } else {
            $row = new $model;
        }

        $row->forceFill($match + $attributes);
        $row->save();

        return $row;
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
        $byCode = EmployeeProfile::query()
            ->whereIn('employee_code', self::EMPLOYEE_CODES)
            ->pluck('id', 'employee_code');

        $ids = [];

        foreach (self::EMPLOYEE_CODES as $i => $code) {
            if (isset($byCode[$code])) {
                $ids[$i] = (string) $byCode[$code];
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
