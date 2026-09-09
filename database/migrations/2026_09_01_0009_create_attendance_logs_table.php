<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model AttendanceLog` (prisma/schema.prisma:319-343).
 *
 * Append-only: the source has `logged_at` and no created_at/updated_at at all,
 * so the Eloquent model must carry `public $timestamps = false`.
 *
 * `logged_at` is DATETIME(3) and the 3 is LOAD-BEARING, not decoration. Punch
 * pairing orders by logged_at; collapsing to whole seconds silently reorders a
 * TIME_IN/TIME_OUT pair that landed in the same second. (Note the separate
 * rule on the compute side: ManilaClock::minutesOfDay() TRUNCATES at whole
 * seconds, reproducing the Intl formatter at dtr.ts:101-110, so a punch at
 * 08:15:00.500 against an 08:00 shift with 15 minutes grace is still on time.
 * Millisecond storage, second-granular grace arithmetic — two precisions, both
 * deliberate.)
 *
 * `log_type` and `source` stay free text, matching the source's `String`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->char('employee_id', 26);
            $t->char('site_id', 26)->nullable();

            // TIME_IN or TIME_OUT.
            $t->string('log_type', 20);

            // WEB_GPS, BIOMETRIC or MANUAL.
            $t->string('source', 20);

            // DOUBLE, not DECIMAL: haversineMeters does trigonometry on these.
            $t->double('latitude')->nullable();
            $t->double('longitude')->nullable();

            $t->decimal('distance_meters', 12, 2)->nullable();

            // Recomputed server-side from the site row — never trusted from the
            // client. A punch body carrying is_geofenced is refused outright.
            $t->boolean('is_geofenced')->default(true);
            $t->boolean('is_anomaly')->default(false);
            $t->string('anomaly_note', 300)->nullable();

            $t->dateTime('logged_at', 3);

            $t->index(['employee_id', 'logged_at'], 'attlog_employee_logged_idx');
            $t->index('logged_at', 'attlog_logged_idx');

            // employee_id reuses attlog_employee_logged_idx as its leftmost
            // prefix; site_id has no index in the source, so MySQL creates the
            // implicit one the constraint needs.
            $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            $t->foreign('site_id')->references('id')->on('work_sites')->nullOnDelete();
        });

        $this->dynamicRowFormat('attendance_logs');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }
};
