<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preserves condition, disposal, physical-count and private-document evidence.
 * Each table, index, foreign key and CHECK is guarded independently so a killed
 * cron can retry after any DDL statement. No existing data is rewritten.
 * Rollback loses this evidence and attachment metadata; stored bytes must be
 * retained separately. RESTRICT keys prevent history disappearing with parents.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_incidents')) {
            Schema::create('inventory_incidents', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('item_id', 26);
                $t->char('employee_id', 26)->nullable();
                $t->char('receipt_id', 26)->nullable();
                $t->string('kind', 20);
                $t->date('reported_on');
                $t->string('reference', 160);
                $t->text('details');
                $t->json('item_snapshot');
                $t->json('employee_snapshot')->nullable();
                $t->string('status', 12)->default('OPEN');
                $t->text('resolution')->nullable();
                $t->string('resolution_reference', 160)->nullable();
                $t->unsignedBigInteger('actor_id');
                $t->string('actor_name', 200);
                $t->char('actor_employee_id', 26)->nullable();
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->string('reviewer_name', 200)->nullable();
                $t->dateTime('resolved_at', 3)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasTable('inventory_disposals')) {
            Schema::create('inventory_disposals', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('item_id', 26);
                $t->date('disposed_on');
                $t->string('method', 160);
                $t->text('reason');
                $t->string('authorization_reference', 160);
                $t->char('authorized_by_employee_id', 26);
                $t->json('authorized_by_snapshot');
                $t->json('item_snapshot');
                $t->unsignedBigInteger('actor_id');
                $t->string('actor_name', 200);
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasTable('inventory_counts')) {
            Schema::create('inventory_counts', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('location_id', 26);
                $t->string('location_name', 160);
                $t->date('counted_on');
                $t->string('reference', 160);
                $t->text('notes')->nullable();
                $t->string('status', 12)->default('OPEN');
                $t->text('resolution')->nullable();
                $t->string('resolution_reference', 160)->nullable();
                $t->unsignedBigInteger('actor_id');
                $t->string('actor_name', 200);
                $t->char('actor_employee_id', 26)->nullable();
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->string('reviewer_name', 200)->nullable();
                $t->dateTime('resolved_at', 3)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasTable('inventory_count_lines')) {
            Schema::create('inventory_count_lines', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('count_id', 26);
                $t->char('item_id', 26);
                $t->char('employee_id', 26)->nullable();
                $t->char('receipt_id', 26)->nullable();
                $t->json('item_snapshot');
                $t->string('result', 12);
                $t->text('notes')->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasTable('inventory_attachments')) {
            Schema::create('inventory_attachments', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('receipt_id', 26)->nullable();
                $t->char('disposal_id', 26)->nullable();
                $t->string('file_name', 300);
                $t->string('mime', 120);
                $t->unsignedBigInteger('size');
                $t->string('path', 300);
                $t->unsignedBigInteger('uploaded_by');
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        $references = [
            'inventory_incidents' => ['item_id' => 'inventory_items', 'employee_id' => 'employee_profiles', 'receipt_id' => 'inventory_receipts', 'actor_id' => 'users', 'actor_employee_id' => 'employee_profiles', 'reviewed_by' => 'users'],
            'inventory_disposals' => ['item_id' => 'inventory_items', 'authorized_by_employee_id' => 'employee_profiles', 'actor_id' => 'users'],
            'inventory_counts' => ['location_id' => 'inventory_locations', 'actor_id' => 'users', 'actor_employee_id' => 'employee_profiles', 'reviewed_by' => 'users'],
            'inventory_count_lines' => ['count_id' => 'inventory_counts', 'item_id' => 'inventory_items', 'employee_id' => 'employee_profiles', 'receipt_id' => 'inventory_receipts'],
            'inventory_attachments' => ['receipt_id' => 'inventory_receipts', 'disposal_id' => 'inventory_disposals', 'uploaded_by' => 'users'],
        ];
        foreach ($references as $table => $columns) {
            foreach ($columns as $column => $parent) {
                $name = substr($table, 10).'_'.$column;
                if (! Schema::hasIndex($table, $name.'_idx')) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($column, $name.'_idx'));
                }
                if (! Schema::hasForeignKey($table, [$column])) {
                    Schema::table($table, fn (Blueprint $t) => $t->foreign($column, $name.'_fk')->references('id')->on($parent)->restrictOnDelete());
                }
            }
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
            }
        }
        foreach ([
            ['inventory_disposals', ['item_id'], 'inv_disposal_item_unq'],
            ['inventory_count_lines', ['count_id', 'item_id'], 'inv_count_item_unq'],
            ['inventory_attachments', ['path'], 'inv_attachment_path_unq'],
        ] as [$table, $columns, $name]) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->unique($columns, $name));
            }
        }
        $this->check('inventory_incidents', 'inv_incident_kind_chk', "kind IN ('DAMAGE','LOSS','INSPECTION')");
        $this->check('inventory_incidents', 'inv_incident_status_chk', "status IN ('OPEN','RESOLVED')");
        $this->check('inventory_counts', 'inv_count_status_chk', "status IN ('OPEN','RESOLVED')");
        $this->check('inventory_count_lines', 'inv_count_result_chk', "result IN ('FOUND','NOT_FOUND','UNEXPECTED')");
        $this->check('inventory_attachments', 'inv_attachment_owner_chk', '(receipt_id IS NOT NULL AND disposal_id IS NULL) OR (receipt_id IS NULL AND disposal_id IS NOT NULL)');
    }

    public function down(): void
    {
        foreach (['inventory_attachments', 'inventory_count_lines', 'inventory_counts', 'inventory_disposals', 'inventory_incidents'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }

    private function check(string $table, string $name, string $expression): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $existing = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$table, $name]);
        if ((int) ($existing->n ?? 0) === 0) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }
};
