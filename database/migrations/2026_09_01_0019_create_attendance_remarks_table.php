<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model AttendanceRemark` (prisma/schema.prisma:670-685).
 *
 * A note written against one employee's day on the eDTR. The DTR itself is
 * derived from punches — there is no stored row per day — so a remark someone
 * types needs one of its own. It replaces the sentence the eDTR would otherwise
 * write for that day; it never changes what the day is worth.
 *
 * `author_id` carries NO foreign key. The source declares it as a bare
 * `String?` with a comment naming user_accounts but no Prisma `@relation`, so
 * no constraint was ever generated. Ported literally rather than "improved":
 * adding one now would change what happens when the author's account is
 * deleted.
 *
 * `updated_at` is NOT NULL here — unlike every other table in this port, where
 * it is a nullable addition. The source declares it `@default(now()) @updatedAt`
 * on this model specifically, because "last written by" is the point of the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_remarks', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->char('employee_id', 26);
            $t->date('date');

            // 2,000-char cap in the API.
            $t->text('note');

            // The account row that last wrote it. No FK — see the docblock.
            $t->unsignedBigInteger('author_id')->nullable();

            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3);

            $t->unique(['employee_id', 'date'], 'remarks_employee_date_unq');
            $t->index('date', 'remarks_date_idx');

            $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
        });

        $this->dynamicRowFormat('attendance_remarks');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_remarks');
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
