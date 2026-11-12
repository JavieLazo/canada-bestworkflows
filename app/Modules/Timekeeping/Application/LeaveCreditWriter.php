<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Platform\Database\TransactionMutex;
use App\Support\Access;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The two credit commands share one lock order and historical baseline rule.
 * Call lock() FIRST in their transaction, then lock aggregate rows. Neither
 * writer locks requests, so FileAbsence's request-then-credit order cannot
 * form a cycle with this sentinel-then-credit order.
 */
final class LeaveCreditWriter
{
    public const string LOCK = 'copems:leave-credits';

    public static function lock(): void
    {
        TransactionMutex::acquire(self::LOCK);
    }

    public static function hasHistory(string $employeeId, int $year, string $requestType): bool
    {
        return LeaveCreditEntry::query()->where('employee_id', $employeeId)->where('year', $year)->where('request_type', $requestType)->exists();
    }

    /** Fixtures and later seeds can create an aggregate after the backfill. */
    public static function baseline(LeaveCredit $credit): void
    {
        if (self::hasHistory($credit->employee_id, $credit->year, $credit->request_type)) {
            return;
        }
        $entry = new LeaveCreditEntry([
            'employee_id' => $credit->employee_id,
            'year' => $credit->year,
            'request_type' => $credit->request_type,
            'kind' => 'BASELINE',
            'days' => $credit->credits,
            'note' => 'Existing annual credit grant; earlier movements were not recorded.',
        ]);
        $entry->id = $credit->id;
        if (! $entry->save()) {
            throw new RuntimeException('The existing credit grant could not be recorded in the ledger.');
        }
    }

    /**
     * One ledger entry and the allocation it moves, under the lock the
     * caller holds (`lock()` first): the credit row is locked, baselined and
     * created on first use. Returns the entry's id.
     */
    public static function move(Access $actor, string $employeeId, int $year, string $requestType, string $kind, float $days, string $note): string
    {
        $credit = LeaveCredit::query()->where('employee_id', $employeeId)->where('year', $year)->where('request_type', $requestType)->lockForUpdate()->first();
        if ($credit !== null) {
            self::baseline($credit);
        }
        $credit ??= new LeaveCredit(['employee_id' => $employeeId, 'year' => $year, 'request_type' => $requestType]);
        $credit->credits = LeaveQuantity::normalize(($credit->credits ?? 0.0) + $days);
        if (! $credit->save()) {
            throw new RuntimeException('The leave credits could not be saved.');
        }
        $entryId = (string) Str::ulid();
        self::append($actor, $employeeId, $year, $requestType, $kind, $days, $note, $entryId);

        return $entryId;
    }

    public static function append(Access $actor, string $employeeId, int $year, string $requestType, string $kind, float $days, string $note, ?string $entryId = null): void
    {
        $entry = new LeaveCreditEntry([
            'employee_id' => $employeeId,
            'year' => $year,
            'request_type' => $requestType,
            'kind' => $kind,
            'days' => $days,
            'note' => $note,
            'source_year' => $kind === 'CARRY_FORWARD' ? $year - 1 : null,
        ]);
        if ($entryId !== null) {
            $entry->id = $entryId;
        }
        $entry->recorded_by = $actor->userId === null ? null : (int) $actor->userId;
        if (! $entry->save()) {
            throw new RuntimeException('The ledger entry could not be saved.');
        }
    }
}
