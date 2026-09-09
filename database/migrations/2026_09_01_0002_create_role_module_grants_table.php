<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model RoleModuleGrant` (prisma/schema.prisma:91-100).
 *
 * `module` was a PostgreSQL native enum. It becomes varchar(20) plus a CHECK
 * constraint plus App\Domain\Enums\ModuleCode — never a MySQL ENUM, which
 * sorts by declaration ordinal, needs an ALTER TABLE to extend, and coerces an
 * unknown value to '' outside strict mode.
 *
 * No created_at and no updated_at: the source model has neither, so the
 * Eloquent model must carry `public $timestamps = false`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_module_grants', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->char('role_id', 26);

            // 'EMPLOYEES' is the longest of the twelve at 9 characters.
            $t->string('module', 20);

            $t->unique(['role_id', 'module'], 'rmg_role_module_unq');

            // Declared after the unique so MySQL reuses it for the constraint.
            $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        $this->dynamicRowFormat('role_module_grants');

        // Enforced on MySQL 8.0.16+ / MariaDB 10.2.1+, parsed-and-ignored below
        // that. App\Domain\Enums\ModuleCode and Rule::in() are the real gate.
        // Values are literal rather than interpolated from the PHP class so a
        // later edit to that class cannot silently rewrite this migration.
        $this->check(
            'role_module_grants',
            'rmg_module_chk',
            "`module` IN ('EMPLOYEES','EDTR','ROSTER','LEAVES','PROJECTS','PAYROLL',"
                ."'RECORDS','USERS','AUDIT','PORTAL','TASKS','PROFILE')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('role_module_grants');
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! $this->isMySql()) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }

    private function check(string $table, string $name, string $expression): void
    {
        if (! $this->isMySql()) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
