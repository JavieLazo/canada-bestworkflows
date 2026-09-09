<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One punch. Append-only.
 *
 * The table has no created_at: `logged_at` IS the row's timestamp, and the
 * source declares it `@default(now())`. Mapping `CREATED_AT` onto it is the
 * literal port — Eloquent fills it on insert only when the writer did not set
 * it, exactly as Prisma's default did.
 *
 * `logged_at` is DATETIME(3) holding UTC. Millisecond precision is
 * load-bearing: punch pairing orders by `logged_at`, and collapsing to whole
 * seconds silently reorders a pair. Never read the Manila calendar day off it
 * with `->toDateString()` — that is what `ManilaClock::dayKey()` is for.
 *
 * `log_type` (TIME_IN | TIME_OUT) and `source` (WEB_GPS | BIOMETRIC | MANUAL |
 * CORRECTION) stay free text, matching the source: they are open sets the UI
 * extends, and an enum would reject values the app produces today. CORRECTION
 * is this port's own addition and needed no migration for exactly that reason.
 *
 * `is_geofenced` is recomputed server-side from the site row on every write and
 * is never trusted from the client, even though it is mass-assignable here —
 * the FormRequest allowlist is what keeps it out of a request body.
 *
 * @property string $id
 * @property string $employee_id
 * @property string|null $site_id
 * @property string $log_type
 * @property string $source
 * @property float|null $latitude
 * @property float|null $longitude
 * @property float|null $distance_meters
 * @property bool $is_geofenced
 * @property bool $is_anomaly
 * @property string|null $anomaly_note
 * @property CarbonImmutable $logged_at
 * @property-read EmployeeProfile|null $employee
 * @property-read WorkSite|null $site
 */
class AttendanceLog extends BaseModel
{
    use ScopesToActor;

    /** `logged_at` is this table's only timestamp, and carries @default(now()). */
    public const CREATED_AT = 'logged_at';

    public const UPDATED_AT = null;

    /**
     * The `source` of a punch typed into the DTR correction dialog.
     *
     * Held apart from MANUAL, which is an admin recording a punch AT the
     * station. Both are hand entered and the distinction is not pedantry: a
     * station punch is happening now, and a correction is somebody saying what
     * happened on a day that has already passed. The Live Feed shows the first
     * and hides the second.
     *
     * A constant because two files have to agree on the spelling exactly —
     * CorrectPunches writes it, LiveFeed excludes it — and a typo in either
     * would quietly put corrections back in the feed with nothing failing.
     */
    public const SOURCE_CORRECTION = 'CORRECTION';

    /** @var string */
    protected $table = 'attendance_logs';

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'site_id',
        'log_type',
        'source',
        'latitude',
        'longitude',
        'distance_meters',
        'is_geofenced',
        'is_anomaly',
        'anomaly_note',
        'logged_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'distance_meters' => 'float',
            'is_geofenced' => 'boolean',
            'is_anomaly' => 'boolean',
            'logged_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }

    /** @return BelongsTo<WorkSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(WorkSite::class, 'site_id');
    }
}
