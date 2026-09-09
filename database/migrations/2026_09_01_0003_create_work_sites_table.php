<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model WorkSite` (prisma/schema.prisma:270-285).
 *
 * Coordinates are DOUBLE, matching the legacy `double precision` and the
 * source's `Float`. They are NOT money and must not be DECIMAL: haversineMeters
 * does trigonometry on them, and the geofence radius is metres of slack.
 *
 * `name` carries no unique index in the source. It is not added here — two
 * sites may legitimately share a name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sites', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();

            // Cap from SiteInput (src/lib/api/schemas.ts:132).
            $t->string('name', 160);

            $t->double('latitude')->default(0);
            $t->double('longitude')->default(0);

            // Signed INT to match the source's `Int`; the API caps it at 100000.
            $t->integer('radius_meters')->default(100);

            // Field work: a punch from anywhere counts as in-bounds.
            $t->boolean('geofence_exempt')->default(false);

            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();
        });

        $this->dynamicRowFormat('work_sites');
    }

    public function down(): void
    {
        Schema::dropIfExists('work_sites');
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
