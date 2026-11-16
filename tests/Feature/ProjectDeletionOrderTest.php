<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\ProjectTaskFile;
use App\Modules\WorkManagement\Application\Commands\DeleteProject;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('deletes only this projects work before its parent and keeps file cleanup and audit intact', function () {
    Storage::fake('local');
    $owner = cpaUser();
    $client = cpaClient($owner);
    $removed = cpaProject($owner, $client, 'Removed');
    $kept = cpaProject($owner, $client, 'Kept');
    $task = cpaTask($owner, $removed, []);
    $other = cpaTask($owner, $kept, []);
    $before = $other->getRawOriginal();
    ProjectTaskFile::create(['task_id' => $task->id, 'kind' => 'BRIEF', 'file_name' => 'brief.pdf', 'mime' => 'application/pdf', 'size' => 5, 'path' => 'task-files/brief.pdf']);
    Storage::disk('local')->put('task-files/brief.pdf', 'proof');
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(app(DeleteProject::class)->handle(Access::forUser($owner), $removed->id))->toBe($removed->code);
    $deletes = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $sql): bool => str_starts_with(strtolower($sql), 'delete')));
    DB::disableQueryLog();
    expect($deletes)->toHaveCount(2);
    expect($deletes[0])->toContain('project_tasks')->and($deletes[1])->toContain('projects');
    expect($task->fresh())->toBeNull()->and($other->fresh()->getRawOriginal())->toBe($before)
        ->and(AuditLog::query()->where('action', 'PROJECT_DELETED')->where('entity_id', $removed->id)->count())->toBe(1);
    Storage::disk('local')->assertMissing('task-files/brief.pdf');
});
