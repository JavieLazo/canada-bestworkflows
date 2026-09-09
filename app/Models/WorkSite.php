<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place employees punch in and out at.
 *
 * `latitude`/`longitude` are double precision, not DECIMAL — the source typed
 * them `Float` and the haversine in `Geofence` consumes doubles.
 * `geofence_exempt` is field work: a punch from anywhere counts as in-bounds.
 *
 * `assertUsableGeofence` (src/lib/api/schemas.ts:146-159) refuses a
 * non-exempt site at (0,0) or with radius < 1; that check belongs in the
 * FormRequest, not here, because the columns keep their 0 defaults.
 *
 * @property string $id
 * @property string $name
 * @property float $latitude
 * @property float $longitude
 * @property int $radius_meters
 * @property bool $geofence_exempt
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, EmployeeProfile> $employees
 * @property-read Collection<int, AttendanceLog> $logs
 * @property-read Collection<int, Project> $projects
 */
class WorkSite extends BaseModel
{
    /** @var string */
    protected $table = 'work_sites';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'radius_meters',
        'geofence_exempt',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_meters' => 'integer',
            'geofence_exempt' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<EmployeeProfile, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(EmployeeProfile::class, 'work_site_id');
    }

    /** @return HasMany<AttendanceLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'site_id');
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'site_id');
    }
}
