<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\LeaveConversionTable;
use App\Modules\Timekeeping\Application\ConversionTables;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Saves a conversion table version as a DRAFT: a new one, or a draft
 * edited in place. An approved version never changes; a later version
 * replaces it from its own effective date. The rows are validated as
 * fractions of a day at the version's decimals, every minute and hour
 * present, and stored normalized. One version per effective date.
 */
final class SaveConversionTable
{
    public const int MAX_REFERENCE = 160;

    /**
     * @param  array<string, mixed>  $input  effective_from, hours_per_day, decimals, rounding, reference, rows
     * @return string the version's id
     */
    public function handle(Access $access, ?string $id, array $input): string
    {
        if (! LeaveSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! ConversionTables::ready()) {
            throw new RuntimeException('Conversion tables can be added once this installation\'s upgrade finishes.');
        }
        $from = trim((string) ($input['effective_from'] ?? ''));
        if (! PayrollPeriod::isPayrollDate($from)) {
            throw new RuntimeException('Choose the date this table takes effect.');
        }
        $hours = $input['hours_per_day'] ?? null;
        if ((! is_string($hours) && ! is_int($hours) && ! is_float($hours)) || ! is_numeric($hours)) {
            throw new RuntimeException('Enter the hours in a working day.');
        }
        try {
            $hoursPerDay = (float) $hours;
            MinuteConversion::dayMinutes($hoursPerDay);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
        $decimals = $input['decimals'] ?? null;
        if ((! is_int($decimals) && ! (is_string($decimals) && preg_match('/\A\d\z/', $decimals) === 1)) || (int) $decimals > MinuteConversion::MAX_DECIMALS) {
            throw new RuntimeException('Choose from 0 to '.MinuteConversion::MAX_DECIMALS.' decimals.');
        }
        $decimals = (int) $decimals;
        $rounding = (string) ($input['rounding'] ?? '');
        if (! in_array($rounding, MinuteConversion::ROUNDINGS, true)) {
            throw new RuntimeException('Choose how a half is rounded.');
        }
        $reference = trim((string) ($input['reference'] ?? ''));
        if (mb_strlen($reference) > self::MAX_REFERENCE) {
            throw new RuntimeException('Keep the reference to '.self::MAX_REFERENCE.' characters.');
        }
        $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
        $rowsError = MinuteConversion::rowsError($rows, $hoursPerDay, $decimals);
        if ($rowsError !== null) {
            throw new RuntimeException($rowsError);
        }
        $rows = MinuteConversion::normalizeRows($rows, $hoursPerDay, $decimals);

        return DB::transaction(static function () use ($access, $id, $from, $hoursPerDay, $decimals, $rounding, $reference, $rows): string {
            $row = $id === null ? new LeaveConversionTable : LeaveConversionTable::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That version no longer exists.');
            }
            if ($row->exists && $row->status !== LeaveConversionTable::DRAFT) {
                throw new RuntimeException('An approved version never changes. Add a new version from a later date instead.');
            }
            $clash = LeaveConversionTable::query()->where('effective_from', $from)->when($row->exists, static fn ($q) => $q->whereKeyNot($row->id))->exists();
            if ($clash) {
                throw new RuntimeException('A version already takes effect on that date.');
            }
            $row->fill([
                'effective_from' => $from, 'hours_per_day' => $hoursPerDay, 'decimals' => $decimals, 'rounding' => $rounding,
                'rows' => $rows, 'reference' => $reference === '' ? null : $reference, 'status' => LeaveConversionTable::DRAFT,
            ]);
            if (! $row->exists) {
                $row->created_by = $access->userId === null ? null : (int) $access->userId;
                $row->created_by_name = $access->fullName;
            }
            if (! $row->save()) {
                throw new RuntimeException('The version could not be saved.');
            }
            $differences = count(MinuteConversion::differences($rows, $hoursPerDay, $decimals, $rounding));
            DB::afterCommit(static fn () => AuditService::record($access, AuditEvent::LEAVE_CONVERSION_TABLE_SAVED, 'leave_conversion_tables', $row->id,
                "Conversion table draft from {$from} saved: {$hoursPerDay} hours a day, {$decimals} decimals, ".MinuteConversion::ROUNDING_LABELS[$rounding]
                .($differences === 0 ? ', every row as the formula gives it' : ", {$differences} ".($differences === 1 ? 'row differs' : 'rows differ').' from the formula')
                .($reference === '' ? '' : " ({$reference})")));

            return $row->id;
        });
    }
}
