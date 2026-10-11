<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\EmployeePosition;
use App\Modules\Workforce\Application\EmployeeSectionWriter;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Application\PdsRowWriter;
use App\Modules\Workforce\Application\RecordChangeData;
use App\Modules\Workforce\Application\RecordChangeSubmission;
use App\Modules\Workforce\Application\RecordPortraitChanges;
use App\Modules\Workforce\Domain\Records\OwnRecordFields;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Own-record edits become frozen proposals; no submission mutates the official employee record. */
final class SubmitRecordChange
{
    /** @param array<string, mixed> $form */
    public function section(Access $access, string $section, array $form, string $reason = ''): string
    {
        return DB::transaction(function () use ($access, $section, $form, $reason): string {
            abort_unless(($access->hasModule(ModuleCode::PROFILE) || $access->hasModule(ModuleCode::EMPLOYEES)) && $access->hasEmployeeLink(), 403);
            if (! OwnRecordFields::allowsSection($section)) {
                throw new RuntimeException(OwnRecordFields::SECTION_REFUSAL);
            }
            abort_unless(app(AccountEmployeeLinks::class)->isActiveLinked((int) $access->userId, $access->ownEmployeeId()), 403);
            $employee = $this->employee($access);
            RecordChangeData::load($employee);
            $current = RecordChangeData::sectionValues($employee, $section);
            foreach ($current as $key => $value) {
                if (OwnRecordFields::allowsField($section, $key)) {
                    continue;
                }
                if (array_key_exists($key, $form) && RecordChangeData::canonical($form[$key]) !== $value) {
                    throw new RuntimeException(OwnRecordFields::EMPLOYEE_ID_REFUSAL);
                }
                // A read-only field omitted by the form must retain its official value.
                $form[$key] = $value;
            }
            $payload = app(EmployeeSectionWriter::class)->payload($employee, $section, $form);
            [$before, $after] = RecordChangeData::delta($current, $payload);
            // KEEP is an instruction, not a change to the stored position.
            if (($after[EmployeePosition::FORM_KEY] ?? null) === EmployeePosition::KEEP) {
                unset($before[EmployeePosition::FORM_KEY], $after[EmployeePosition::FORM_KEY]);
            }
            $draft = [];
            foreach ($after as $key => $value) {
                $draft[$key] = array_key_exists($key, $form) ? $form[$key] : (is_bool($value) ? $value : '');
            }
            $changes = RecordChangeData::changes($employee, $section, $before, $after);

            return app(RecordChangeSubmission::class)->store($access, $employee, 'SECTION', $section, 'UPDATE', null, $before, $after, $draft, $changes, $reason)->id;
        });
    }

    /** @param array<string, mixed> $form */
    public function pds(Access $access, string $list, ?string $rowId, array $form, string $reason = '', bool $delete = false): string
    {
        if (! in_array($list, PdsLists::KEYS, true)) {
            throw new RuntimeException('Choose a Personal Data Sheet list.');
        }
        if (! PdsLists::ready($list)) {
            throw new RuntimeException('Run the pending migrations before editing this list.');
        }

        return DB::transaction(function () use ($access, $list, $rowId, $form, $reason, $delete): string {
            $employee = $this->employee($access);
            $writer = app(PdsRowWriter::class);
            $row = $rowId === null ? null : $writer->find($employee, $list, $rowId);
            if (($rowId !== null && $row === null) || ($delete && $row === null)) {
                throw new RuntimeException('That entry is no longer on this employee record.');
            }
            $before = $row === null ? [] : RecordChangeData::rowValues($row, $list);
            $columns = [];
            if (! $delete) {
                $errors = PdsLists::errors($list, $form);
                if ($errors !== []) {
                    throw new RuntimeException((string) reset($errors));
                }
                if ($rowId === null && $writer->count($employee, $list) >= PdsLists::MAX_ROWS) {
                    throw new RuntimeException(PdsLists::capMessage($list));
                }
                $columns = PdsLists::columns($list, $form);
            }
            $after = array_map(RecordChangeData::canonical(...), $columns);
            $operation = $delete ? 'DELETE' : ($rowId === null ? 'CREATE' : 'UPDATE');
            if ($operation === 'UPDATE') {
                [$before, $after] = RecordChangeData::delta($before, $after);
            }
            $draft = [];
            foreach ($after as $key => $value) {
                $draft[$key] = array_key_exists($key, $form) ? $form[$key] : (is_bool($value) ? $value : '');
            }
            $changes = RecordChangeData::changes($employee, $list, $before, $after, true);

            return app(RecordChangeSubmission::class)->store($access, $employee, 'PDS', $list, $operation, $rowId, $before, $after, $draft, $changes, $reason)->id;
        });
    }

    public function portrait(Access $access, ?UploadedFile $upload, string $reason = ''): string
    {
        abort_unless(($access->hasModule(ModuleCode::PROFILE) || $access->hasModule(ModuleCode::EMPLOYEES)) && $access->hasEmployeeLink(), 403);

        return app(RecordPortraitChanges::class)->submit($access, $upload, $reason);
    }

    private function employee(Access $access): EmployeeProfile
    {
        abort_unless(($access->hasModule(ModuleCode::PROFILE) || $access->hasModule(ModuleCode::EMPLOYEES)) && $access->hasEmployeeLink(), 403);
        abort_unless(app(AccountEmployeeLinks::class)->isActiveLinked((int) $access->userId, $access->ownEmployeeId()), 403);
        $employee = EmployeeProfile::query()->whereKey($access->ownEmployeeId())->lockForUpdate()->first();
        if ($employee === null) {
            throw new RuntimeException('The linked employee record is no longer available.');
        }

        return $employee;
    }
}
