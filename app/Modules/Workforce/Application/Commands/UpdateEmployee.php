<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\EmployeeDetailRows;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\EmployeeInput;
use App\Modules\Workforce\Application\EmployeePosition;
use App\Modules\Workforce\Application\EmployeeSectionWriter;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Platform\Records\AccountEmployeeLinks;
use App\Platform\Retention\EmployeeObligations;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Applies authorized Employee Records management edits, including the manager’s linked record. */
final class UpdateEmployee
{
    /** @param array<string, mixed> $form */
    public function handle(Access $access, string $id, string $sectionId, array $form, bool $acknowledgeObligations = false, string $obligationReason = ''): ?string
    {
        abort_unless($access->mayReadAllData(ModuleCode::EMPLOYEES), 403);
        $section = EmployeeFields::section($sectionId);
        if ($section === null) {
            return null;
        }
        $carriesPosition = in_array(EmployeePosition::FORM_KEY, array_column($section['fields'], 'choice'), true);
        $saved = DB::transaction(static function () use ($access, $id, $sectionId, $form, $carriesPosition, $acknowledgeObligations, $obligationReason): ?array {
            abort_unless(app(AccountEmployeeLinks::class)->isActive((int) $access->userId), 403);
            // The structure mutex first, before the profile lock, whenever
            // the section links a position (EmployeePosition::lock()).
            if ($carriesPosition) {
                EmployeePosition::lock();
            }
            if ($sectionId === 'employment') {
                app(EmployeeObligations::class)->lock();
            }
            // Read the current row under the same lock as its update and history.
            // Otherwise Eloquent can omit a concurrently changed column from its
            // dirty payload while the history copies the stale in-memory value.
            $employee = EmployeeProfile::query()->whereKey($id)->lockForUpdate()->first();
            if ($employee === null) {
                return null;
            }
            // Read behind the profile lock, so the checks below and the
            // writes see what is stored now (EmployeeDetailRows: lock order).
            if (EmployeeDetailRows::tablesReady()) {
                $employee->load(['pdsDetail', 'addresses']);
            }
            $writer = app(EmployeeSectionWriter::class);
            $payload = $writer->payload($employee, $sectionId, $form);
            $obligationNote = '';
            if ($employee->is_active && array_key_exists('is_active', $payload) && ! $payload['is_active']) {
                $outstanding = app(EmployeeObligations::class)->outstanding($employee->id);
                if ($outstanding !== []) {
                    $reason = trim($obligationReason);
                    if (! $acknowledgeObligations || $reason === '' || mb_strlen($reason) > 1000) {
                        throw new RuntimeException('Outstanding items remain. Acknowledge them and give a reason of at most 1,000 characters before marking this employee inactive.');
                    }
                    $obligationNote = ' — Inactivated with '.count($outstanding).' outstanding items acknowledged: '.$reason;
                }
            }
            $beforeEmployment = $sectionId === 'employment' ? EmploymentHistoryRecorder::snapshot($employee) : null;
            $writer->write($employee, $payload);
            if ($beforeEmployment !== null) {
                app(EmploymentHistoryRecorder::class)->updated($access, $employee, $beforeEmployment);
            }

            return [$employee, $payload, $obligationNote];
        });
        if ($saved === null) {
            return null;
        }
        [$employee, $payload, $obligationNote] = $saved;
        AuditService::record($access, AuditEvent::EMPLOYEE_UPDATED, 'employee_profiles', $employee->id, EmployeeName::display($employee).' — '.implode(', ', EmployeeInput::auditKeys(array_keys($payload))).' updated'.$obligationNote);

        return $section['title'];
    }
}
