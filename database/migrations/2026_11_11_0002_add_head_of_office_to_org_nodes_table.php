<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE HEAD OF AN OFFICE. The Application for Leave names the "Authorized
 * official/Head of office" of the applicant's own office, so each department
 * and unit may carry a typed name and designation. Both are NULL until HR
 * sets them, which reads as a blank line on the form.
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: each column stands behind its own guard, in both directions.
 *
 * ROLLBACK LOSES every head HR entered; the form's line reads blank again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('org_nodes') && ! Schema::hasColumn('org_nodes', 'head_name')) {
            Schema::table('org_nodes', static fn (Blueprint $t) => $t->string('head_name', 200)->nullable());
        }
        if (Schema::hasTable('org_nodes') && ! Schema::hasColumn('org_nodes', 'head_designation')) {
            Schema::table('org_nodes', static fn (Blueprint $t) => $t->string('head_designation', 200)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('org_nodes') && Schema::hasColumn('org_nodes', 'head_designation')) {
            Schema::table('org_nodes', static fn (Blueprint $t) => $t->dropColumn('head_designation'));
        }
        if (Schema::hasTable('org_nodes') && Schema::hasColumn('org_nodes', 'head_name')) {
            Schema::table('org_nodes', static fn (Blueprint $t) => $t->dropColumn('head_name'));
        }
    }
};
