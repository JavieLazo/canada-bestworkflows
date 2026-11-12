<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\LeaveType;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\LeaveEligibility;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
use App\Platform\Records\ReferenceCatalogs;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Sets one leave type's settings (LeaveTypeCredits): how it is filed and
 * its set days, how a request's days are counted, which details of leave a
 * request asks for (LeaveApplication), whether it earns, its
 * unit, what may be charged to it, its charge order, its below-zero
 * limit, whether and within what limits it may be converted to cash
 * (LeaveCashOutLimits), whether it is paid out on separation, and who may
 * use it (LeaveEligibility: employment statuses re-resolved against the
 * Employment list, and sex). Whoever manages Leave Settings or leave
 * (LeaveSettingsAccess).
 * The rules apply from the next filing, approval, earning posting and
 * payroll finalized; nothing already approved or posted changes. Audited
 * once per save that changes something.
 *
 * A CHARGED TYPE NEEDS A CHARGE ORDER NO OTHER CHARGED TYPE HOLDS
 * (LeaveTypeCredits::orderClashes); a save that would share one is
 * refused, whatever else it changes.
 */
final class SaveLeaveTypeCredits
{
    /** The saved type's key among the others: no leave type's token can be blank. */
    private const string SELF = '';

    /** @param  array<string, mixed>  $input */
    public function handle(Access $access, string $typeId, array $input): string
    {
        if (! LeaveSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! Schema::hasColumn('leave_types', 'earns_credits')) {
            throw new RuntimeException('Leave credit rules can be set once this installation\'s upgrade finishes.');
        }
        $error = LeaveTypeCredits::error($input);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        // Who may use the type names Employment list entries: each one is
        // re-resolved against the list as it stands.
        $statusNames = [];
        foreach (app(ReferenceCatalogs::class)->for('employment')->options($access) as $option) {
            $statusNames[$option['id']] = $option['name'];
        }
        $error = LeaveEligibility::error($input, array_keys($statusNames));
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $rules = LeaveTypeCredits::fromInput($input);
        if (! $rules->eligibility->isEveryone() && ! LeaveTypeCreditRecords::eligibilityReady()) {
            throw new RuntimeException('Who may use a leave type can be set once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $typeId, $rules, $statusNames): string {
            // Every type, locked: the charge order is checked against the
            // others, and two saves at once must not both take one number.
            $rows = LeaveType::query()->orderBy('name')->lockForUpdate()->get();
            $type = $rows->first(static fn (LeaveType $row): bool => $row->getKey() === $typeId);
            if ($type === null) {
                throw new RuntimeException('That leave type no longer exists.');
            }
            if ($rules->charged()) {
                $types = LeaveTypeCreditRecords::rulesOf($rows->reject(static fn (LeaveType $row): bool => $row->getKey() === $typeId));
                $types[self::SELF] = ['name' => (string) $type->getAttribute('name'), 'rules' => $rules];
                $clash = LeaveTypeCredits::orderClashes($types)[self::SELF] ?? [];
                if ($clash !== []) {
                    throw new RuntimeException(LeaveTypeCredits::orderClashText($rules->chargeOrder, $clash));
                }
            }
            $values = $rules->toArray();
            if (! Schema::hasColumn('leave_types', 'can_cash_out')) {
                // The window before the cron migrates 2026_10_31_0008.
                unset($values['can_cash_out']);
            }
            if (! Schema::hasColumn('leave_types', 'year_end')) {
                // The window before the cron migrates 2026_11_02_0017.
                unset($values['year_end']);
            }
            if (! Schema::hasColumn('leave_types', 'filing')) {
                // The window before the cron migrates 2026_11_07_0001.
                unset($values['filing'], $values['allowance_days'], $values['day_count']);
            }
            if (! LeaveTypeCreditRecords::detailsReady()) {
                // The window before the cron migrates 2026_11_08_0002.
                unset($values['leave_details']);
            }
            if (! LeaveTypeCreditRecords::cashOutLimitsReady()) {
                // The window before the cron migrates 2026_11_09_0001.
                $values = array_diff_key($values, $rules->cashOutLimits->toArray(), ['paid_on_separation' => true]);
            }
            if (! LeaveTypeCreditRecords::eligibilityReady()) {
                // The window before the cron migrates 2026_11_09_0005.
                $values = array_diff_key($values, $rules->eligibility->toArray());
            }
            $before = $type->only(array_keys($values));
            $type->forceFill($values);
            $changed = $type->isDirty();
            if ($changed && ! $type->save()) {
                throw new RuntimeException('The leave type could not be saved.');
            }
            $name = (string) $type->getAttribute('name');
            if ($changed) {
                DB::afterCommit(static fn () => AuditService::record($access, AuditEvent::LEAVE_TYPE_CREDITS_SET, 'leave_types', $type->id, "{$name} · ".$rules->describe($statusNames)));
            }
            unset($before);

            return $changed ? "Credit rules saved for {$name}." : "No change to {$name}.";
        });
    }
}
