<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model LeaveTravelRequest` (prisma/schema.prisma:345-371).
 *
 * `is_paid` is genuinely THREE-state and must stay nullable:
 *   true  = paid leave
 *   false = leave without pay
 *   null  = official business travel (not leave at all)
 * A NOT NULL is_paid would record every OB travel request as unpaid leave,
 * which is a payroll deduction. Any code reading it must test is_null() before
 * it tests === false.
 *
 * `request_type`, `day_type` and `status` stay free text, matching the source:
 * request_type is a `leave_types.name` normalised to a key and that list is
 * user-editable in Records Management, so an enum would reject values the app
 * happily produces today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_travel_requests', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->string('code', 40)->unique();
            $t->char('employee_id', 26);

            // A leave_types.name normalised to a key, or BUSINESS_TRAVEL.
            $t->string('request_type', 60);

            // WHOLE_DAY or HALF_DAY.
            $t->string('day_type', 20)->nullable()->default('WHOLE_DAY');

            $t->date('start_date');
            $t->date('end_date');

            // 2,000-char cap in the API; TEXT rather than a VARCHAR that wide.
            $t->text('reason')->nullable();

            // PENDING, APPROVED, REJECTED or WITHDRAWN.
            $t->string('status', 20)->default('PENDING');

            // See the docblock — nullable is load-bearing.
            $t->boolean('is_paid')->nullable();

            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at', 3)->nullable();

            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();

            $t->index(['employee_id', 'start_date'], 'leave_employee_start_idx');
            $t->index('status', 'leave_status_idx');

            $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            $t->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });

        $this->dynamicRowFormat('leave_travel_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_travel_requests');
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
