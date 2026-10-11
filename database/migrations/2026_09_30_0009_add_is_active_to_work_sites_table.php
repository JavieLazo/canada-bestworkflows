<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `work_sites.is_active`: a site can be retired without being
 * deleted (the owner's decision, 2026-09-30).
 *
 * Schedule history keeps every site it ever named (2026_09_30_0003–0007), so
 * a site that is no longer used cannot be deleted; it is made INACTIVE
 * instead. An inactive site stays on every record and every existing
 * assignment that names it — punches are still judged against it — and
 * only stops being offered for NEW assignments. Deactivating changes no
 * employee's schedule.
 *
 * TRUE by default, so every existing site stays active and nothing changes
 * when this runs. No TypeScript counterpart (DELIBERATE DIVERGENCE): the
 * reference could only delete a site.
 *
 * RETRY-SAFE: one ALTER behind its own `hasColumn` guard, appended without
 * `->after()` so MariaDB 11.4 adds it instantly; `down()` is guarded the same
 * way. ROLLBACK LOSES which sites were inactive: every site reads active
 * again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('work_sites', 'is_active')) {
            Schema::table('work_sites', static fn (Blueprint $t) => $t->boolean('is_active')->default(true));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_sites', 'is_active')) {
            Schema::table('work_sites', static fn (Blueprint $t) => $t->dropColumn('is_active'));
        }
    }
};
