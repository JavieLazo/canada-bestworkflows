<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Models\ProjectRecordFile;
use App\Modules\WorkManagement\Application\ReportAccess;
use App\Modules\WorkManagement\Contracts\Views\RecordFileView;
use App\Modules\WorkManagement\Infrastructure\FinanceScope;
use App\Modules\WorkManagement\Infrastructure\SharedWorkScope;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProjectRecordFiles
{
    /** @param list<string> $ids */
    public function allowsReferences(Access $actor, string $projectId, array $ids): bool
    {
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            if ($this->scope($actor)->where('project_id', $projectId)->whereKey($chunk)->count() !== count($chunk)) {
                return false;
            }
        }

        return true;
    }

    /** @return Collection<int, RecordFileView> */
    public function choices(Access $actor, string $projectId): Collection
    {
        return $this->scope($actor)->where('project_id', $projectId)->whereNull('removed_at')->orderByDesc('created_at')->limit(100)->get()
            ->map(static fn (ProjectRecordFile $row): RecordFileView => Snapshot::of($row, RecordFileView::class));
    }

    public function canEdit(Access $actor, string $projectId, string $type, string $id): bool
    {
        if ($type === 'REPORT') {
            $report = app(ProjectReports::class)->report($actor, $projectId, $id);

            return $report !== null && $report->status === 'DRAFT' && app(ProjectReports::class)->canEdit($actor, $projectId, $id);
        }
        $financial = in_array($type, ['CASH_ENTRY', 'CONTRIBUTION_DELIVERY'], true);
        $edit = $financial ? FinanceScope::projects($actor, edit: true)->whereKey($projectId)->exists()
            : app(ProjectResults::class)->permissions($actor, $projectId)['edit'];
        if (! $edit) {
            return false;
        }
        $record = match ($type) {
            'RESULT_ENTRY' => app(ProjectResults::class)->entry($actor, $projectId, $id),
            'CASH_ENTRY' => app(ProjectFinancials::class)->entry($actor, $projectId, $id),
            'CONTRIBUTION_DELIVERY' => app(ProjectFunding::class)->delivery($actor, $projectId, $id),
            default => null,
        };

        return $record !== null && $record->voided_at === null;
    }

    /** @return Collection<int, RecordFileView> */
    public function forRecord(Access $actor, string $projectId, string $type, string $id): Collection
    {
        return $this->scope($actor)->where('project_id', $projectId)->where('entity_type', $type)->where('record_id', $id)->whereNull('removed_at')
            ->orderBy('created_at')->limit(100)->get()->map(static fn (ProjectRecordFile $row): RecordFileView => Snapshot::of($row, RecordFileView::class));
    }

    public function file(Access $actor, string $id): ?RecordFileView
    {
        $row = $this->scope($actor)->whereKey($id)->whereNull('removed_at')->first();

        return $row === null ? null : Snapshot::of($row, RecordFileView::class);
    }

    public function download(Access $actor, string $id, bool $preview = false): StreamedResponse
    {
        $record = $this->file($actor, $id);
        abort_if($record === null || ! Storage::disk('local')->exists($record->path), 404);
        $image = in_array($record->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true);

        return Storage::disk('local')->response($record->path, $record->file_name,
            ['Content-Type' => $record->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store'],
            $image && $preview ? 'inline' : 'attachment');
    }

    public function submitted(Access $actor, string $projectId, string $submissionId, string $id, bool $preview = false): StreamedResponse
    {
        $submission = app(ProjectReports::class)->submission($actor, $projectId, $submissionId);
        $saved = null;
        foreach ($submission->snapshot['files'] ?? [] as $file) {
            if (is_array($file) && ($file['id'] ?? null) === $id) {
                $saved = $file;
                break;
            }
        }
        $record = $this->scope($actor)->where('project_id', $projectId)->whereKey($id)->whereNotNull('retained_at')->firstOrFail();
        abort_unless(is_array($saved) && hash_equals($record->sha256, $saved['sha256'] ?? '') && Storage::disk('local')->exists($record->path), 404);
        $image = in_array($record->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true);

        return Storage::disk('local')->response($record->path, $record->file_name,
            ['Content-Type' => $record->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store'], $image && $preview ? 'inline' : 'attachment');
    }

    /** @return Builder<ProjectRecordFile> */
    private function scope(Access $actor): Builder
    {
        return ProjectRecordFile::query()->where(static fn (Builder $query) => $query
            ->where(static fn (Builder $query) => $query->where('entity_type', 'RESULT_ENTRY')->whereIn('project_id', SharedWorkScope::projects($actor)->select('projects.id'))
                ->whereExists(static fn ($entry) => $entry->selectRaw('1')->from('project_result_entries')->whereColumn('id', 'project_record_files.record_id')->whereColumn('project_id', 'project_record_files.project_id')))
            ->orWhere(static fn (Builder $query) => $query->where('entity_type', 'CONTRIBUTION_DELIVERY')->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))
                ->whereExists(static fn ($entry) => $entry->selectRaw('1')->from('project_contribution_deliveries')->whereColumn('id', 'project_record_files.record_id')->whereColumn('project_id', 'project_record_files.project_id')))
            ->orWhere(static fn (Builder $query) => $query->where('entity_type', 'REPORT')->whereIn('record_id', ReportAccess::reports($actor)->select('project_reports.id')))
            ->orWhere(static fn (Builder $query) => $query->where('entity_type', 'CASH_ENTRY')->whereIn('project_id', FinanceScope::projects($actor)->select('projects.id'))
                ->whereExists(static fn ($entry) => $entry->selectRaw('1')->from('project_cash_entries')->whereColumn('id', 'project_record_files.record_id')->whereColumn('project_id', 'project_record_files.project_id'))));
    }
}
