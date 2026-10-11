<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the live property register separate from immutable issue-time evidence.
 * RESTRICT protects employee, item and receipt history from cascading deletion;
 * catalog links also restrict deletion until the live register stops using them.
 * Account IDs may become null while their copied names retain attribution.
 *
 * RETRY-SAFE: each create, index, foreign key, CHECK and sentinel insert has its
 * own guard. A killed cron resumes at the first missing statement. Rollback
 * loses inventory and all receipts/movements; it never alters employee records.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory_categories', 'inventory_locations'] as $table) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, function (Blueprint $t): void {
                    $t->engine = 'InnoDB';
                    $t->char('id', 26)->primary();
                    $t->string('name', 160);
                    $t->dateTime('created_at', 3);
                    $t->dateTime('updated_at', 3)->nullable();
                });
            }
        }
        if (! Schema::hasTable('inventory_items')) {
            Schema::create('inventory_items', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->string('code', 40);
                $t->string('name', 240);
                $t->string('serial_no', 160)->nullable();
                $t->date('acquired_on');
                $t->decimal('cost', 12, 2);
                $t->char('category_id', 26)->nullable();
                $t->char('location_id', 26)->nullable();
                $t->string('condition', 16)->default('SERVICEABLE');
                $t->string('status', 12)->default('IN_STOCK');
                $t->char('employee_id', 26)->nullable();
                $t->char('current_receipt_id', 26)->nullable();
                $t->text('remarks')->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasTable('inventory_receipts')) {
            Schema::create('inventory_receipts', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->string('code', 40);
                $t->char('employee_id', 26);
                $t->char('issuer_employee_id', 26);
                $t->date('issued_on');
                $t->string('office_name', 240);
                $t->json('employee_snapshot');
                $t->json('issuer_snapshot');
                $t->text('remarks')->nullable();
                $t->string('status', 12)->default('OPEN');
                $t->char('corrected_from_id', 26)->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->string('recorder_name', 200);
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasTable('inventory_receipt_lines')) {
            Schema::create('inventory_receipt_lines', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('receipt_id', 26);
                $t->char('item_id', 26);
                $t->string('status', 12)->default('ISSUED');
                $t->json('item_snapshot');
                $t->date('ended_on')->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasTable('inventory_movements')) {
            Schema::create('inventory_movements', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('item_id', 26);
                $t->char('receipt_id', 26)->nullable();
                $t->string('event_type', 40);
                $t->char('from_employee_id', 26)->nullable();
                $t->char('to_employee_id', 26)->nullable();
                $t->date('occurred_on');
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('actor_name', 200);
                $t->text('remarks')->nullable();
                $t->dateTime('created_at', 3);
            });
        }

        foreach ([
            ['inventory_categories', 'invcat_name_unq', ['name'], true],
            ['inventory_locations', 'invloc_name_unq', ['name'], true],
            ['inventory_items', 'invi_code_unq', ['code'], true],
            ['inventory_items', 'invi_category_idx', ['category_id'], false],
            ['inventory_items', 'invi_location_idx', ['location_id'], false],
            ['inventory_items', 'invi_employee_idx', ['employee_id'], false],
            ['inventory_items', 'invi_receipt_idx', ['current_receipt_id'], false],
            ['inventory_items', 'invi_status_idx', ['status', 'condition'], false],
            ['inventory_receipts', 'invr_code_unq', ['code'], true],
            ['inventory_receipts', 'invr_employee_idx', ['employee_id', 'issued_on'], false],
            ['inventory_receipts', 'invr_issuer_idx', ['issuer_employee_id'], false],
            ['inventory_receipts', 'invr_corrected_unq', ['corrected_from_id'], true],
            ['inventory_receipts', 'invr_recorder_idx', ['recorded_by'], false],
            ['inventory_receipt_lines', 'invrl_receipt_item_unq', ['receipt_id', 'item_id'], true],
            ['inventory_receipt_lines', 'invrl_item_idx', ['item_id'], false],
            ['inventory_movements', 'invm_item_idx', ['item_id', 'occurred_on'], false],
            ['inventory_movements', 'invm_receipt_idx', ['receipt_id'], false],
            ['inventory_movements', 'invm_from_idx', ['from_employee_id'], false],
            ['inventory_movements', 'invm_to_idx', ['to_employee_id'], false],
            ['inventory_movements', 'invm_actor_idx', ['actor_id'], false],
        ] as [$table, $name, $columns, $unique]) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, function (Blueprint $t) use ($name, $columns, $unique): void {
                    $unique ? $t->unique($columns, $name) : $t->index($columns, $name);
                });
            }
        }
        foreach ([
            ['inventory_items', 'category_id', 'inventory_categories', 'invi_category_fk', false],
            ['inventory_items', 'location_id', 'inventory_locations', 'invi_location_fk', false],
            ['inventory_items', 'employee_id', 'employee_profiles', 'invi_employee_fk', false],
            ['inventory_items', 'current_receipt_id', 'inventory_receipts', 'invi_receipt_fk', false],
            ['inventory_receipts', 'employee_id', 'employee_profiles', 'invr_employee_fk', false],
            ['inventory_receipts', 'issuer_employee_id', 'employee_profiles', 'invr_issuer_fk', false],
            ['inventory_receipts', 'corrected_from_id', 'inventory_receipts', 'invr_corrected_fk', false],
            ['inventory_receipts', 'recorded_by', 'users', 'invr_recorder_fk', true],
            ['inventory_receipt_lines', 'receipt_id', 'inventory_receipts', 'invrl_receipt_fk', false],
            ['inventory_receipt_lines', 'item_id', 'inventory_items', 'invrl_item_fk', false],
            ['inventory_movements', 'item_id', 'inventory_items', 'invm_item_fk', false],
            ['inventory_movements', 'receipt_id', 'inventory_receipts', 'invm_receipt_fk', false],
            ['inventory_movements', 'from_employee_id', 'employee_profiles', 'invm_from_fk', false],
            ['inventory_movements', 'to_employee_id', 'employee_profiles', 'invm_to_fk', false],
            ['inventory_movements', 'actor_id', 'users', 'invm_actor_fk', true],
        ] as [$table, $column, $target, $name, $nullable]) {
            if (! Schema::hasForeignKey($table, [$column])) {
                Schema::table($table, function (Blueprint $t) use ($column, $target, $name, $nullable): void {
                    $key = $t->foreign($column, $name)->references('id')->on($target);
                    $nullable ? $key->nullOnDelete() : $key->restrictOnDelete();
                });
            }
        }
        if ($this->isMySql()) {
            foreach (['inventory_categories', 'inventory_locations', 'inventory_items', 'inventory_receipts', 'inventory_receipt_lines', 'inventory_movements'] as $table) {
                DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
            }
            foreach ([
                ['inventory_items', 'invi_condition_chk', "`condition` IN ('SERVICEABLE','DAMAGED','UNSERVICEABLE','MISSING')"],
                ['inventory_items', 'invi_status_chk', "`status` IN ('IN_STOCK','ISSUED','DISPOSED')"],
                ['inventory_items', 'invi_custody_chk', "(`status` = 'ISSUED' AND `employee_id` IS NOT NULL AND `current_receipt_id` IS NOT NULL) OR (`status` <> 'ISSUED' AND `employee_id` IS NULL AND `current_receipt_id` IS NULL)"],
                ['inventory_items', 'invi_cost_chk', '`cost` >= 0'],
                ['inventory_receipts', 'invr_status_chk', "`status` IN ('OPEN','CLOSED','SUPERSEDED')"],
                ['inventory_receipt_lines', 'invrl_status_chk', "`status` IN ('ISSUED','RETURNED','TRANSFERRED','SUPERSEDED')"],
                ['inventory_receipt_lines', 'invrl_ended_chk', "(`status` = 'ISSUED' AND `ended_on` IS NULL) OR (`status` <> 'ISSUED' AND `ended_on` IS NOT NULL)"],
            ] as [$table, $name, $expression]) {
                if (! $this->hasCheck($table, $name)) {
                    DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                }
            }
        }
        if (! DB::table('locks')->where('name', 'copems:inventory')->exists()) {
            DB::table('locks')->insert(['name' => 'copems:inventory', 'created_at' => now()->utc()->format('Y-m-d H:i:s.v')]);
        }
    }

    public function down(): void
    {
        foreach (['inventory_movements', 'inventory_receipt_lines', 'inventory_items', 'inventory_receipts', 'inventory_locations', 'inventory_categories'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
        DB::table('locks')->where('name', 'copems:inventory')->delete();
    }

    private function hasCheck(string $table, string $name): bool
    {
        $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$table, $name]);

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
