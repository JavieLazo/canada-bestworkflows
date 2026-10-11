<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\EmployeePosition;
use App\Modules\Workforce\Application\EmployeeSectionWriter;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Application\PdsRowWriter;
use App\Modules\Workforce\Application\RecordChangeData;
use App\Modules\Workforce\Application\RecordPortraitChanges;
use App\Modules\Workforce\Domain\Records\OwnRecordFields;
use App\Platform\Notifications\Notifier;
use App\Platform\Records\AccountEmployeeLinks;
use App\Platform\Retention\EmployeeObligations;
use App\Support\Access;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** An independent review commits the exact approved record and its history in one transaction. */
final class ReviewRecordChange
{
    public function handle(Access $access, string $id, bool $approve, string $note = ''): void
    {
        abort_unless($access->mayReadAllData(ModuleCode::EMPLOYEES), 403);
        $note = trim($note);
        if (mb_strlen($note) > 1000 || (! $approve && $note === '')) {
            throw new RuntimeException('Give a review note of at most 1,000 characters. A rejection needs a reason.');
        }
        $hint = EmployeeRecordChangeRequest::query()->findOrFail($id);
        $publicPhoto = null;
        try {
            DB::transaction(function () use ($access, $id, $approve, $note, $hint, &$publicPhoto): void {
                $reviewerId = (int) $access->userId;
                $requesterId = $hint->requested_by;
                $links = app(AccountEmployeeLinks::class)->lockCurrentLinks(array_values(array_unique(array_filter([$reviewerId, $requesterId], static fn (?int $id): bool => $id !== null))));
                abort_unless(array_key_exists($reviewerId, $links) && $links[$reviewerId] !== $hint->employee_id, 403);
                abort_if($requesterId === $reviewerId || ($access->hasEmployeeLink() && $access->ownEmployeeId() === $hint->employee_id), 403);
                if ($approve && ($requesterId === null || ! array_key_exists($requesterId, $links) || $links[$requesterId] !== $hint->employee_id)) {
                    throw new RuntimeException('The requesting account is no longer active and linked to this employee. Reject the request and ask for a new submission.');
                }
                if ($hint->kind === 'SECTION' && $hint->section === 'employment') {
                    EmployeePosition::lock();
                    app(EmployeeObligations::class)->lock();
                }
                $employee = EmployeeProfile::query()->whereKey($hint->employee_id)->lockForUpdate()->firstOrFail();
                $request = EmployeeRecordChangeRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($request->status !== EmployeeRecordChangeRequest::PENDING) {
                    throw new RuntimeException('This request has already been resolved.');
                }
                if ($approve) {
                    match ($request->kind) {
                        'SECTION' => $this->section($employee, $request),
                        'PDS' => $this->pds($employee, $request),
                        'PORTRAIT' => $publicPhoto = app(RecordPortraitChanges::class)->apply($employee, $request),
                        'DOCUMENT' => throw new RuntimeException('Document changes must be made by management. Reject this request and update the employee record directly.'),
                        default => throw new RuntimeException('This request kind cannot be reviewed.'),
                    };
                }
                $request->status = $approve ? EmployeeRecordChangeRequest::APPROVED : EmployeeRecordChangeRequest::REJECTED;
                $request->pending_employee_id = null;
                $request->reviewed_by = (int) $access->userId;
                $request->reviewed_by_name = $access->fullName;
                $request->review_note = $note === '' ? null : $note;
                $request->reviewed_at = CarbonImmutable::now()->utc();
                $request->save();
                if ($approve) {
                    app(EmploymentHistoryRecorder::class)->approved($access, $employee, $request);
                }
                AuditService::record($access, AuditEvent::EMPLOYEE_UPDATED, 'employee_record_change_requests', $request->id, $approve ? 'Employee record change approved' : 'Employee record change rejected');
                DB::afterCommit(static fn () => Notifier::employees([$request->employee_id], [$access->userId], $approve ? 'RECORD_CHANGE_APPROVED' : 'RECORD_CHANGE_REJECTED', $approve ? 'Your record change was approved' : 'Your record change was rejected', 'Open your change request to see the decision.', '/my-record/requests?request='.$request->id));
            });
        } catch (Throwable $error) {
            if ($publicPhoto !== null) {
                app(RecordPortraitChanges::class)->discard($publicPhoto);
            }
            throw $error;
        }
    }

    private function section(EmployeeProfile $employee, EmployeeRecordChangeRequest $request): void
    {
        if (! OwnRecordFields::allowsSection($request->section)) {
            throw new RuntimeException(OwnRecordFields::SECTION_REFUSAL);
        }
        foreach (array_unique([...array_keys($request->baseline), ...array_keys($request->proposed), ...array_keys($request->form)]) as $key) {
            if (! OwnRecordFields::allowsField($request->section, $key)) {
                throw new RuntimeException(OwnRecordFields::EMPLOYEE_ID_REFUSAL);
            }
        }
        RecordChangeData::load($employee);
        $current = RecordChangeData::sectionValues($employee, $request->section);
        RecordChangeData::assertCurrent($request->baseline, $current);
        $form = array_replace(EmployeeFields::toForm($employee), $request->form);
        $writer = app(EmployeeSectionWriter::class);
        $payload = $writer->payload($employee, $request->section, $form);
        [$before, $after] = RecordChangeData::delta($current, $payload);
        if (($after[EmployeePosition::FORM_KEY] ?? null) === EmployeePosition::KEEP) {
            unset($before[EmployeePosition::FORM_KEY], $after[EmployeePosition::FORM_KEY]);
        }
        // Current values outside the requested patch must survive the review.
        if (array_diff_key($after, $request->proposed) !== [] || array_diff_key($request->proposed, $after) !== []) {
            throw new RuntimeException(RecordChangeData::STALE);
        }
        RecordChangeData::assertCurrent($request->proposed, $after);
        if (RecordChangeData::changes($employee, $request->section, $before, $after) !== $request->changes) {
            throw new RuntimeException(RecordChangeData::STALE);
        }
        if ($employee->is_active && array_key_exists('is_active', $after) && $after['is_active'] === false && app(EmployeeObligations::class)->outstanding($employee->id) !== []) {
            throw new RuntimeException('Outstanding items must be resolved before this inactivation request can be approved.');
        }
        $writer->write($employee, $payload);
    }

    private function pds(EmployeeProfile $employee, EmployeeRecordChangeRequest $request): void
    {
        $list = $request->section;
        if (! in_array($list, PdsLists::KEYS, true) || ! PdsLists::ready($list)) {
            throw new RuntimeException('This Personal Data Sheet list is not available.');
        }
        $writer = app(PdsRowWriter::class);
        $row = $request->row_id === null ? null : $writer->find($employee, $list, $request->row_id);
        if ($request->operation !== 'CREATE' && $row === null) {
            throw new RuntimeException(RecordChangeData::STALE);
        }
        $current = $row === null ? [] : RecordChangeData::rowValues($row, $list);
        RecordChangeData::assertCurrent($request->baseline, $current);
        if ($request->operation === 'DELETE') {
            $row?->delete();

            return;
        }
        $form = $current;
        foreach ($form as $key => $value) {
            if (is_bool($value)) {
                $form[$key] = $value ? 'Y' : 'N';
            } elseif ($value !== null) {
                $form[$key] = (string) $value;
            }
        }
        $form = array_replace($form, $request->form);
        $errors = PdsLists::errors($list, $form);
        if ($errors !== []) {
            throw new RuntimeException((string) reset($errors));
        }
        $columns = PdsLists::columns($list, $form);
        $after = array_map(RecordChangeData::canonical(...), $columns);
        if ($request->operation === 'UPDATE') {
            [, $after] = RecordChangeData::delta($current, $after);
        }
        if (array_diff_key($after, $request->proposed) !== [] || array_diff_key($request->proposed, $after) !== []) {
            throw new RuntimeException(RecordChangeData::STALE);
        }
        RecordChangeData::assertCurrent($request->proposed, $after);

        if ($row === null) {
            if ($writer->count($employee, $list) >= PdsLists::MAX_ROWS) {
                throw new RuntimeException(PdsLists::capMessage($list));
            }
            $writer->insert($employee, $list, $columns);
        } else {
            $row->fill($columns)->save();
        }
    }
}
