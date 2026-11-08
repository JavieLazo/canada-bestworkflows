<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device credentials for the biometric eDTR kiosk, and a receipt per offline
 * punch so a phone that retries a sync cannot insert the same Time In twice.
 *
 * The token table stores only a SHA-256 of the bearer secret. Face templates
 * and fingerprints never come to the server — they stay on the device.
 *
 * Each statement is guarded so a cPanel migrate that dies between statements
 * can be run again. Rollback drops the kiosk credentials and the receipts.
 * Attendance rows already accepted stay; they are the time record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('edtr_mobile_tokens')) {
            Schema::create('edtr_mobile_tokens', static function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->unsignedBigInteger('user_id');
                $table->string('device_id', 64);
                $table->string('ability', 16);
                $table->char('token_hash', 64);
                $table->dateTime('last_used_at', 3)->nullable();
                $table->dateTime('revoked_at', 3)->nullable();
                $table->dateTime('created_at', 3);
            });
        }

        if (! Schema::hasIndex('edtr_mobile_tokens', 'emt_hash_uq')) {
            Schema::table('edtr_mobile_tokens', static function (Blueprint $table): void {
                $table->unique('token_hash', 'emt_hash_uq');
            });
        }

        if (! Schema::hasIndex('edtr_mobile_tokens', 'emt_device_idx')) {
            Schema::table('edtr_mobile_tokens', static function (Blueprint $table): void {
                $table->index('device_id', 'emt_device_idx');
            });
        }

        if (! Schema::hasForeignKey('edtr_mobile_tokens', ['user_id'])) {
            Schema::table('edtr_mobile_tokens', static function (Blueprint $table): void {
                $table->foreign('user_id', 'emt_user_fk')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('edtr_mobile_punch_receipts')) {
            Schema::create('edtr_mobile_punch_receipts', static function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->string('client_id', 36);
                $table->char('attendance_log_id', 26)->nullable();
                $table->string('message', 500);
                $table->dateTime('created_at', 3);
            });
        }

        if (! Schema::hasIndex('edtr_mobile_punch_receipts', 'empr_client_uq')) {
            Schema::table('edtr_mobile_punch_receipts', static function (Blueprint $table): void {
                $table->unique('client_id', 'empr_client_uq');
            });
        }

        if (! Schema::hasForeignKey('edtr_mobile_punch_receipts', ['attendance_log_id'])) {
            Schema::table('edtr_mobile_punch_receipts', static function (Blueprint $table): void {
                $table->foreign('attendance_log_id', 'empr_log_fk')->references('id')->on('attendance_logs')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('edtr_mobile_punch_receipts');
        Schema::dropIfExists('edtr_mobile_tokens');
    }
};
