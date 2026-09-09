<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fourth name part — "Jr.", "III", "Sr." — held apart from `last_name` so a
 * sort or a search on the surname is not thrown off by it.
 *
 * Free text at 20, not an enum: the option list is short but not closed
 * (Roman numerals run past V, and "Jr" / "Jr." / "JR" all arrive from paper
 * forms), and a lookup table would buy nothing the caps do not already give.
 *
 * On the row-size budget of the create migration: utf8mb4 VARCHAR(20) adds
 * 20 x 4 + 1 = 81 bytes, taking the COMPACT-format declared width from 7,703
 * to 7,784 — still inside the 8,126-byte half-page limit, so this stays an
 * ALTER that MySQL accepts rather than one it rejects at 1118.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $t): void {
            $t->string('suffix', 20)->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $t): void {
            $t->dropColumn('suffix');
        });
    }
};
