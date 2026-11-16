<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Rollback refuses funding, contributions, allocations and opening records rather than erasing financial history.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_fundings')) {
            Schema::create('project_fundings', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->string('name', 200);
                $table->string('provider', 200)->nullable();
                $table->unsignedBigInteger('committed_minor')->nullable();
                $table->date('expected_on')->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 16)->default('ACTIVE');
                $table->unsignedInteger('revision')->default(1);
                $table->json('history')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->index(['project_id', 'status'], 'pf_project_status_idx');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_funding_installments')) {
            Schema::create('project_funding_installments', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->char('funding_id', 26);
                $table->string('name', 160);
                $table->unsignedBigInteger('planned_minor');
                $table->date('planned_on')->nullable();
                $table->text('conditions')->nullable();
                $table->string('status', 16)->default('ACTIVE');
                $table->unsignedInteger('revision')->default(1);
                $table->json('history')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->index(['funding_id', 'status'], 'pfi_funding_status_idx');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('funding_id')->references('id')->on('project_fundings')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_contributions')) {
            Schema::create('project_contributions', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->string('name', 200);
                $table->string('contributor', 200);
                $table->string('kind', 12);
                $table->string('unit', 60)->nullable();
                $table->bigInteger('quantity_scaled')->nullable();
                $table->unsignedBigInteger('committed_minor')->nullable();
                $table->text('valuation_basis')->nullable();
                $table->date('expected_on')->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 16)->default('ACTIVE');
                $table->unsignedInteger('revision')->default(1);
                $table->json('history')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->index(['project_id', 'kind', 'status'], 'pc_project_kind_idx');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_contribution_deliveries')) {
            Schema::create('project_contribution_deliveries', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->char('contribution_id', 26);
                $table->date('occurred_on');
                $table->bigInteger('quantity_scaled')->nullable();
                $table->unsignedBigInteger('value_minor')->nullable();
                $table->text('valuation_basis')->nullable();
                $table->string('reference', 200)->nullable();
                $table->text('notes')->nullable();
                $table->char('replaces_id', 26)->nullable();
                $table->dateTime('voided_at', 3)->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason', 1000)->nullable();
                $table->unsignedBigInteger('created_by');
                $table->dateTime('created_at', 3);
                $table->dateTime('updated_at', 3)->nullable();
                $table->index(['contribution_id', 'voided_at', 'occurred_on'], 'pcd_contribution_date_idx');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('contribution_id')->references('id')->on('project_contributions')->restrictOnDelete();
                $table->foreign('replaces_id')->references('id')->on('project_contribution_deliveries')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('voided_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('project_cash_allocations')) {
            Schema::create('project_cash_allocations', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('id', 26)->primary();
                $table->char('project_id', 26);
                $table->char('entry_id', 26);
                $table->unsignedInteger('revision');
                $table->string('source_kind', 16)->default('NONE');
                $table->char('source_id', 26)->nullable();
                $table->string('source_label', 200)->nullable();
                $table->char('installment_id', 26)->nullable();
                $table->string('installment_label', 160)->nullable();
                $table->char('group_id', 26)->nullable();
                $table->string('group_label', 200)->nullable();
                $table->unsignedBigInteger('amount_minor');
                $table->string('reason', 1000)->nullable();
                $table->unsignedBigInteger('created_by');
                $table->dateTime('created_at', 3);
                $table->index(['entry_id', 'revision'], 'pca_entry_revision_idx');
                $table->index(['source_kind', 'source_id'], 'pca_source_idx');
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('entry_id')->references('id')->on('project_cash_entries')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
        if (! Schema::hasColumn('project_cash_entries', 'allocation_revision')) {
            Schema::table('project_cash_entries', static fn (Blueprint $table) => $table->unsignedInteger('allocation_revision')->default(0));
        }
        foreach (['opening_on', 'opening_minor', 'opening_reference', 'opening_notes', 'opening_revision', 'opening_history'] as $column) {
            if (! Schema::hasColumn('project_finances', $column)) {
                Schema::table('project_finances', static function (Blueprint $table) use ($column): void {
                    match ($column) {
                        'opening_on' => $table->date($column)->nullable(), 'opening_minor' => $table->bigInteger($column)->nullable(),
                        'opening_revision' => $table->unsignedInteger($column)->default(0), 'opening_history' => $table->json($column)->nullable(),
                        default => $table->text($column)->nullable(),
                    };
                });
            }
        }
        foreach (['period_id', 'period_label', 'period_starts_on', 'period_ends_on', 'funding_id', 'contribution_id', 'source_label'] as $column) {
            if (! Schema::hasColumn('project_budget_lines', $column)) {
                Schema::table('project_budget_lines', static function (Blueprint $table) use ($column): void {
                    match ($column) {
                        'period_starts_on', 'period_ends_on' => $table->date($column)->nullable(),
                        'period_id', 'funding_id', 'contribution_id' => $table->char($column, 26)->nullable(),
                        default => $table->string($column, 200)->nullable(),
                    };
                });
            }
        }
    }

    public function down(): void
    {
        $tables = ['project_cash_allocations', 'project_contribution_deliveries', 'project_contributions', 'project_funding_installments', 'project_fundings'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Keep funding and contributions while financial records exist. Back up and explicitly clear them before rollback.');
            }
        }
        if (Schema::hasColumn('project_finances', 'opening_minor') && DB::table('project_finances')->where(static fn ($query) => $query->whereNotNull('opening_minor')->orWhere('opening_revision', '>', 0))->exists()) {
            throw new RuntimeException('Keep the recorded opening cash history.');
        }
        foreach (['period_id', 'funding_id', 'contribution_id'] as $column) {
            if (Schema::hasColumn('project_budget_lines', $column) && DB::table('project_budget_lines')->whereNotNull($column)->exists()) {
                throw new RuntimeException('Keep the approved budget reporting links.');
            }
        }
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
        foreach (['project_cash_entries' => ['allocation_revision'], 'project_finances' => ['opening_on', 'opening_minor', 'opening_reference', 'opening_notes', 'opening_revision', 'opening_history'],
            'project_budget_lines' => ['period_id', 'period_label', 'period_starts_on', 'period_ends_on', 'funding_id', 'contribution_id', 'source_label']] as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, static fn (Blueprint $definition) => $definition->dropColumn($column));
                }
            }
        }
    }
};
