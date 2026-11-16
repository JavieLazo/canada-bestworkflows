<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectRecordFile;
use App\Modules\WorkManagement\Application\RecordEvidence;
use App\Platform\Files\AttachmentStore;
use App\Platform\Files\UploadLimits;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AttachRecordEvidence
{
    public function handle(Access $actor, string $projectId, string $type, string $recordId, UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        Validator::make(['evidence' => $file, 'fileName' => $name], ['evidence' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp',
            'fileName' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/']])->validate();
        $size = (int) $file->getSize();
        if ($size > UploadLimits::maxFileBytes()) {
            throw ValidationException::withMessages(['evidence' => 'The evidence file exceeds the shared upload limit.']);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        if ($hash === false) {
            throw ValidationException::withMessages(['evidence' => 'The file could not be read. Choose it again.']);
        }
        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $existing = DB::transaction(static function () use ($actor, $projectId, $type, $recordId, $hash): ?ProjectRecordFile {
            $record = RecordEvidence::locked($actor, $projectId, $type, $recordId, true);

            return ProjectRecordFile::query()->where('project_id', $record->getAttribute('project_id'))->where('sha256', $hash)->first();
        });
        $persist = static function (string $path) use ($actor, $projectId, $type, $recordId, $name, $mime, $size, $hash): string {
            $record = RecordEvidence::locked($actor, $projectId, $type, $recordId, true);
            $shared = ProjectRecordFile::query()->where('project_id', $record->getAttribute('project_id'))->where('sha256', $hash)->first();
            if ($shared !== null && $shared->path !== $path && Storage::disk('local')->exists($shared->path)) {
                $staged = $path;
                $path = $shared->path;
                DB::afterCommit(static fn () => Storage::disk('local')->delete($staged));
            }
            $same = ProjectRecordFile::query()->where('project_id', $record->getAttribute('project_id'))->where('entity_type', $type)
                ->where('record_id', $record->getKey())->where('sha256', $hash)->lockForUpdate()->first();
            if ($same !== null) {
                $same->removed_at = null;
                $same->save();

                return $same->id;
            }
            $row = new ProjectRecordFile;
            $row->forceFill(['project_id' => $record->getAttribute('project_id'), 'entity_type' => $type, 'record_id' => $record->getKey(),
                'file_name' => $name, 'path' => $path, 'mime_type' => $mime, 'file_size' => $size, 'sha256' => $hash, 'uploaded_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::PROJECT_RECORD_FILE_ATTACHED, 'projects', $record->getAttribute('project_id'), 'Project evidence attached');

            return $row->id;
        };
        if ($existing !== null && Storage::disk('local')->exists($existing->path)) {
            return DB::transaction(static fn (): string => $persist($existing->path));
        }

        return app(AttachmentStore::class)->store($file, 'project-records/'.$projectId, (string) Str::ulid(), $persist, UploadLimits::storageCeilingBytes(),
            lockOwner: static function () use ($actor, $projectId, $type, $recordId): void {
                RecordEvidence::locked($actor, $projectId, $type, $recordId, true);
            });
    }
}
