<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Names pre-ledger allocations honestly: their earlier movements were never
 * recorded. Preserve each credit's exact amount and id. Record the baseline
 * NOW: a mutable allocation's creation date cannot prove when its current
 * amount became available.
 *
 * RETRY-SAFE — snapshot the entire still-missing set before chunked inserts,
 * never page a set that shrinks as rows land. A credit with any ledger entry
 * already has history and must not receive a second baseline on a later run.
 * The writers' sentinel protects this copy from a concurrent manual change.
 *
 * ROLLBACK RETAINS baselines: deleting them after newer movements would make
 * the ledger incomplete. Rolling back the create removes the whole ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(static function (): void {
            DB::table('locks')->where('name', 'copems:leave-credits')->lockForUpdate()->get();
            $rows = DB::table('leave_credits as credit')->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('leave_credit_entries as entry')
                    ->whereColumn('entry.employee_id', 'credit.employee_id')
                    ->whereColumn('entry.year', 'credit.year')
                    ->whereColumn('entry.request_type', 'credit.request_type');
            })->get();
            $recordedAt = now()->utc()->format('Y-m-d H:i:s.v');

            foreach ($rows->chunk(200) as $chunk) {
                $entries = [];
                foreach ($chunk as $row) {
                    $entries[] = [
                        'id' => $row->id,
                        'employee_id' => $row->employee_id,
                        'year' => $row->year,
                        'request_type' => $row->request_type,
                        'kind' => 'BASELINE',
                        'days' => $row->credits,
                        'note' => 'Existing annual allocation; earlier movements were not recorded.',
                        'source_year' => null,
                        'recorded_by' => null,
                        'created_at' => $recordedAt,
                        'updated_at' => null,
                    ];
                }
                DB::table('leave_credit_entries')->insert($entries);
            }
        });
    }

    public function down(): void
    {
        // History remains until the ledger table itself is removed.
    }
};
