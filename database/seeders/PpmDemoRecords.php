<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ProjectRecordFile;
use App\Models\ProjectTaskFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Uid\Ulid;

abstract class PpmDemoRecords extends PortSeeder
{
    public static function id(string $key): string
    {
        return '01M6KVBCHM'.substr((string) Ulid::fromBinary(substr(hash('sha256', 'ems-ppm-demo-v1:'.$key, true), 0, 16)), -16);
    }

    /** @param class-string<Model> $model
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $match
     */
    protected function keep(string $model, string $key, array $attributes, array $match = []): bool
    {
        $match = $match === [] ? ['id' => self::id($key)] : $match;
        if ($model::query()->where($match)->exists()) {
            return false;
        }
        // Existing demo edits and retained history are never rewritten by a rerun.
        //
        // THE PACK KEEPS ITS OWN FIXED IDS, so the row is written here and not
        // through upsert(): every demo row points at the others by id(), and
        // upsert() gives a new row a generated key and files the fixed one
        // away as a seed identity — the first row pointing at it would then
        // name a record that does not exist.
        (new $model)->forceFill($match + $attributes)->save();

        return true;
    }

    protected function attachment(string $projectId, string $recordId, int $actorId, ?string $entity = null, ?string $employeeId = null): string
    {
        $key = $recordId.':file:'.($entity ?? 'task').($employeeId ?? '');
        $id = self::id($key);
        $image = $entity === null && $employeeId === null;
        $extension = $image ? 'png' : 'pdf';
        $path = 'ppm-demo/'.$projectId.'/'.$id.'.'.$extension;
        $source = database_path('seeders/assets/community/'.($image ? 'court.png' : '201-file-checklist.pdf'));
        $bytes = file_get_contents($source);
        if ($bytes === false) {
            throw new \RuntimeException('The demo attachment is missing.');
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($path) && ! $disk->put($path, $bytes)) {
            throw new \RuntimeException('The demo attachment could not be stored.');
        }
        $attributes = ['file_name' => 'Demo sample.'.$extension, 'path' => $path, 'uploaded_by' => $actorId];
        if ($entity === null) {
            $this->keep(ProjectTaskFile::class, $key, [...$attributes, 'task_id' => $recordId, 'employee_id' => $employeeId,
                'kind' => $employeeId === null ? 'BRIEF' : 'SUBMISSION', 'mime' => $image ? 'image/png' : 'application/pdf', 'size' => strlen($bytes)]);
        } else {
            $this->keep(ProjectRecordFile::class, $key, [...$attributes, 'project_id' => $projectId, 'entity_type' => $entity, 'record_id' => $recordId,
                'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        }

        return $id;
    }
}
