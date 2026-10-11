<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeRecordChangeRequest;
use App\Modules\Workforce\Contracts\Views\RecordChangeRequestView;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/** Scoped request projections never expose stored paths or another employee's proposal to self-service. */
final class RecordChangeRequests
{
    /** @return LengthAwarePaginator<int, RecordChangeRequestView> */
    public function page(Access $access, bool $own = false, string $status = 'PENDING', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        if (! in_array($status, ['ALL', 'PENDING', 'APPROVED', 'REJECTED', 'WITHDRAWN'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose a request status.']);
        }
        $query = $this->scoped($access, $own);
        if ($status !== 'ALL') {
            $query->where('status', $status);
        }
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(max(1, min(100, $perPage)), ['*'], 'page', max(1, $page));

        return new LengthAwarePaginator(
            $rows->getCollection()->map(fn (EmployeeRecordChangeRequest $row): RecordChangeRequestView => $this->view($access, $row)),
            $rows->total(), $rows->perPage(), $rows->currentPage(), $rows->getOptions(),
        );
    }

    public function find(Access $access, string $id, bool $own = false): ?RecordChangeRequestView
    {
        $row = $this->scoped($access, $own)->find($id);

        return $row === null ? null : $this->view($access, $row);
    }

    /** @return array{path:string,name:string,mime:string}|null */
    public function evidence(Access $access, string $id, string $which): ?array
    {
        if (! in_array($which, ['before', 'after'], true)) {
            return null;
        }
        $row = $this->scoped($access, ! $access->mayReadAllData(ModuleCode::EMPLOYEES))->find($id);
        if ($row === null) {
            return null;
        }
        if ($row->kind === 'PORTRAIT') {
            $path = $row->form[$which === 'before' ? 'photo_previous_path' : 'photo_proposed_path'] ?? null;

            return is_string($path) ? ['path' => $path, 'name' => $which.'-portrait.jpg', 'mime' => 'image/jpeg'] : null;
        }
        if ($row->kind === 'DOCUMENT' && $access->mayReadAllData(ModuleCode::EMPLOYEES) && $access->employeeId !== $row->employee_id && (($which === 'before' && $row->operation === 'DELETE') || ($which === 'after' && $row->operation === 'CREATE'))) {
            $path = $row->form['document_path'] ?? null;

            return is_string($path) ? ['path' => $path, 'name' => (string) ($row->form['document_file_name'] ?? 'document'), 'mime' => (string) ($row->form['document_mime'] ?? 'application/octet-stream')] : null;
        }

        return null;
    }

    /** @return Builder<EmployeeRecordChangeRequest> */
    private function scoped(Access $access, bool $own): Builder
    {
        $query = EmployeeRecordChangeRequest::query();
        if ($own) {
            if (! ($access->hasModule(ModuleCode::PROFILE) || $access->hasModule(ModuleCode::EMPLOYEES)) || ! $access->hasEmployeeLink()) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('employee_id', $access->ownEmployeeId());
        }
        if (! $access->mayReadAllData(ModuleCode::EMPLOYEES)) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function view(Access $access, EmployeeRecordChangeRequest $row): RecordChangeRequestView
    {
        $own = $access->hasEmployeeLink() && $access->ownEmployeeId() === $row->employee_id;
        $pending = $row->status === EmployeeRecordChangeRequest::PENDING;
        $form = $row->form;
        $documentEvidence = $row->kind === 'DOCUMENT' && $access->mayReadAllData(ModuleCode::EMPLOYEES) && ! $own;
        $before = ($row->kind === 'PORTRAIT' && is_string($form['photo_previous_path'] ?? null)) || ($documentEvidence && $row->operation === 'DELETE');
        $after = ($row->kind === 'PORTRAIT' && is_string($form['photo_proposed_path'] ?? null)) || ($documentEvidence && $row->operation === 'CREATE');

        return new RecordChangeRequestView([
            'id' => $row->id, 'employee_id' => $row->employee_id, 'employee_name' => $row->employee_name,
            'kind' => $row->kind, 'section' => $row->section, 'operation' => $row->operation,
            'status' => $row->status, 'reason' => $row->reason, 'review_note' => $row->review_note,
            'requested_by_name' => $row->requested_by_name, 'submitted_by_name' => $row->requested_by_name,
            'reviewed_by_name' => $row->reviewed_by_name,
            'created_at' => $row->created_at, 'submitted_at' => $row->created_at,
            'reviewed_at' => $row->reviewed_at, 'withdrawn_at' => $row->withdrawn_at,
            'changes' => $row->changes,
            'can_review' => $pending && $access->mayReadAllData(ModuleCode::EMPLOYEES) && ! $own && (string) $row->requested_by !== (string) $access->userId,
            'can_withdraw' => $pending && $own && ($access->hasModule(ModuleCode::PROFILE) || $access->hasModule(ModuleCode::EMPLOYEES)),
            'has_before_evidence' => $before, 'has_after_evidence' => $after,
        ]);
    }
}
