<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Models\EdtrMobilePunchReceipt;
use App\Modules\Timekeeping\Application\PunchInput;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Writes biometric punches captured on a device, including ones queued
 * while the kiosk had no network. Each row is the same RecordPunch the
 * eDTR station uses, at the instant the device captured. A row that arrives
 * on a later day than it was captured is refused when a posted payslip
 * already covers its day (RecordPunch).
 *
 * A DEVICE SET UP AT A SITE PUNCHES ONLY THAT SITE'S PEOPLE. A row may name
 * the site its device stands at (`site_id`); RecordPunch then treats it as
 * the station's site select and refuses anyone who works somewhere else that
 * day, by name. A row with no site is judged by its coordinates alone.
 */
final class SyncMobilePunches
{
    public function __construct(private readonly RecordPunch $punches) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{client_id: string, status: string, message: string, log_id: ?string}>
     */
    public function handle(Access $actor, array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            $left = $this->sortKey($a['logged_at'] ?? '');
            $right = $this->sortKey($b['logged_at'] ?? '');

            return $left <=> $right;
        });

        $results = [];
        foreach ($rows as $row) {
            $clientId = (string) ($row['client_id'] ?? '');
            $results[] = $this->one($actor, $clientId, $row);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{client_id: string, status: string, message: string, log_id: ?string}
     */
    private function one(Access $actor, string $clientId, array $row): array
    {
        try {
            $at = LocalClock::instant((string) ($row['logged_at'] ?? ''));
        } catch (Throwable) {
            return $this->rejected($clientId, 'That punch time could not be read.');
        }

        try {
            return DB::transaction(function () use ($actor, $clientId, $row, $at): array {
                // Payroll's membership lock FIRST, before the receipt is
                // read: RecordPunch may have to answer whether the punch's
                // day is already paid, and the lock also puts two devices'
                // rows in one line, so their receipt inserts never wait on
                // each other's gap.
                app(PostedPayrollDates::class)->lock();

                $existing = EdtrMobilePunchReceipt::query()->where('client_id', $clientId)->lockForUpdate()->first();
                if ($existing !== null) {
                    return [
                        'client_id' => $clientId,
                        'status' => 'synced',
                        'message' => $existing->message,
                        'log_id' => $existing->attendance_log_id,
                    ];
                }

                $createdId = null;
                try {
                    $message = $this->punches->handle(
                        $actor,
                        new PunchInput(
                            (string) ($row['employee_id'] ?? ''),
                            // The site the device stands at, as the station's site select.
                            trim((string) ($row['site_id'] ?? '')),
                            'BIOMETRIC',
                            false,
                            '',
                            '',
                        ),
                        (string) ($row['log_type'] ?? ''),
                        $this->coordinate($row['latitude'] ?? null),
                        $this->coordinate($row['longitude'] ?? null),
                        $this->coordinate($row['accuracy'] ?? null),
                        $at,
                        $createdId,
                    );
                } catch (RuntimeException $e) {
                    return $this->rejected($clientId, $e->getMessage());
                }

                if ($message === null || $createdId === null) {
                    return $this->rejected($clientId, 'Choose Time In or Time Out.');
                }

                $message = mb_substr($message, 0, 500);
                EdtrMobilePunchReceipt::query()->create([
                    'client_id' => $clientId,
                    'attendance_log_id' => $createdId,
                    'message' => $message,
                ]);

                return [
                    'client_id' => $clientId,
                    'status' => 'synced',
                    'message' => $message,
                    'log_id' => $createdId,
                ];
            });
        } catch (QueryException) {
            $existing = EdtrMobilePunchReceipt::query()->where('client_id', $clientId)->first();
            if ($existing !== null) {
                return [
                    'client_id' => $clientId,
                    'status' => 'synced',
                    'message' => $existing->message,
                    'log_id' => $existing->attendance_log_id,
                ];
            }

            throw new RuntimeException('The punch could not be recorded. Please try again.');
        }
    }

    /** @return array{client_id: string, status: string, message: string, log_id: ?string} */
    private function rejected(string $clientId, string $message): array
    {
        return [
            'client_id' => $clientId,
            'status' => 'rejected',
            'message' => $message,
            'log_id' => null,
        ];
    }

    private function sortKey(mixed $value): int
    {
        try {
            return LocalClock::instant((string) $value)->getTimestamp();
        } catch (Throwable) {
            return PHP_INT_MAX;
        }
    }

    private function coordinate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
