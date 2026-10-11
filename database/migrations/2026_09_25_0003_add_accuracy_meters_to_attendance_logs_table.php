<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `attendance_logs.accuracy_meters`, how sure the browser was of
 * the coordinates it handed the punch station.
 *
 * DELIBERATE DIVERGENCE: the reference `model AttendanceLog`
 * (copems prisma/schema.prisma:319-343) stores latitude and longitude and
 * nothing about how good the fix was. Since 2026-09-25 both punch stations
 * ask the browser for a location on every Time In and Time Out, and the DTR
 * correction dialog shows the captured location beside each recorded punch —
 * so the reader needs to know whether "12 m from the site" came from a
 * 5-metre GPS fix or a 900-metre cell-tower guess. This column holds
 * `GeolocationCoordinates.accuracy`: the radius in metres the true position
 * lies within at 95% confidence, kept at the same two-decimal boundary as
 * `distance_meters` (Money::round in RecordPunch).
 *
 * Recorded, never judged: the geofence verdict still compares
 * `distance_meters` with the site radius alone. Nullable with no default and
 * NO BACKFILL, because null means "not measured" — a coordinate pair typed
 * into the kiosk boxes, a punch written by a correction, a punch with no fix
 * at all, and every row recorded before this release. No index: nothing
 * filters or orders by it. Appended without `->after()`, so the ALTER is an
 * instant metadata change on MariaDB 11.4 (the table is ROW_FORMAT=DYNAMIC,
 * 2026_09_01_0009) rather than a table copy.
 *
 * RETRY-SAFE. Production migrates from a temporary cPanel cron that can be
 * killed between statements, and MySQL DDL does not roll back. This
 * migration is one ALTER behind one hasColumn() guard, so a re-run after a
 * kill either adds the column or finds it there and does nothing. down()
 * mirrors that: it drops the column only while it exists, so a repeated
 * rollback is a no-op too.
 *
 * ROLLBACK LOSES every recorded accuracy. The punches, their coordinates and
 * their distances stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('attendance_logs', 'accuracy_meters')) {
            Schema::table('attendance_logs', function (Blueprint $t): void {
                // DECIMAL(12,2) like distance_meters: metres to the centimetre,
                // and the same Money::round boundary on the way in.
                $t->decimal('accuracy_meters', 12, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attendance_logs', 'accuracy_meters')) {
            Schema::table('attendance_logs', function (Blueprint $t): void {
                $t->dropColumn('accuracy_meters');
            });
        }
    }
};
