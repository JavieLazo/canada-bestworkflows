<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model Project` (prisma/schema.prisma:375-393).
 *
 * `name` is UNIQUE. Under utf8mb4_unicode_ci that unique index is case- AND
 * accent-insensitive, where the source's PostgreSQL collation was byte-exact.
 * That is a deliberate trade — it replaces the app's `mode: "insensitive"`
 * duplicate pre-checks for free — but it means two projects whose names differ
 * only by case or accent now collide with a 1062.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->string('code', 40)->unique();
            $t->string('name', 200)->unique();

            // 4,000-char cap in ProjectInput.
            $t->text('description')->nullable();

            $t->char('site_id', 26)->nullable();

            // PLANNING, ACTIVE, ON_HOLD, COMPLETED, CANCELLED — free text, an
            // open set the UI extends.
            $t->string('status', 40)->default('PLANNING');

            $t->date('start_date');
            $t->date('due_date')->nullable();

            $t->unsignedBigInteger('manager_id')->nullable();

            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();

            $t->foreign('site_id')->references('id')->on('work_sites')->nullOnDelete();
            $t->foreign('manager_id')->references('id')->on('users')->nullOnDelete();
        });

        $this->dynamicRowFormat('projects');
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
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
