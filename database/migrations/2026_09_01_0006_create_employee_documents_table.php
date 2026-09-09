<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model EmployeeDocument` (prisma/schema.prisma:241-266).
 *
 * The source's `data String @db.Text` held the file as a base64 data: URL,
 * capped at 2,800,000 characters (MAX_UPLOAD_DATA_URL_CHARS,
 * src/lib/attachments.ts:23). That cannot come across: MySQL TEXT is 65,535
 * BYTES, so a 2.8M-character upload errors 1406 under strict mode and, without
 * it, silently truncates and corrupts the file. LONGTEXT would work but costs
 * 788 bytes of inline row width under ROW_FORMAT=COMPACT and blows
 * max_allowed_packet on every read. The bytes live on the filesystem and this
 * column holds the path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->char('employee_id', 26);

            // Number printed on the document itself (licence no., SSS no.).
            $t->string('document_no', 80)->nullable();

            // What the document is — defaults to the file name, editable.
            $t->string('name', 200);
            $t->string('category', 80);
            $t->date('expires_on')->nullable();

            // Kept separately from `name` so downloads keep the real extension.
            $t->string('file_name', 300);
            $t->string('mime', 120);
            $t->integer('size');

            // Replaces `data` — see the docblock.
            $t->string('path', 255);

            // FK to the account table, which is `users` in this port (bigint).
            $t->unsignedBigInteger('uploaded_by')->nullable();

            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();

            $t->index('employee_id', 'empdoc_employee_idx');
            $t->index('expires_on', 'empdoc_expires_idx');

            $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            $t->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });

        $this->dynamicRowFormat('employee_documents');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
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
