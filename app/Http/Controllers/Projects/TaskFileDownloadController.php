<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Domain\Enums\ModuleCode;
use App\Http\Controllers\Controller;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Support\Access;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /projects/task-files/{file} — the port of GET /api/tasks/files/[id]:
 * the stored attachment itself, as a download.
 *
 * Reachable under PROJECTS *or* TASKS (route group), because both sides of a
 * task read its files; row access then follows the reference — a reviewer
 * (`all_data` + PROJECTS) or ANY of the task's assignees (the pivot, via
 * {@see ProjectTask::hasAssignee()}), nobody else.
 * The reference stored a data URL in the row; this port stores the bytes on
 * the local disk at `path` and streams them back under the original name.
 */
class TaskFileDownloadController extends Controller
{
    public function __invoke(string $file): Response
    {
        $actor = Access::forActor(auth()->user());

        $row = ProjectTaskFile::query()->with('task.assignees')->whereKey($file)->first();
        if ($row === null || $row->task === null) {
            abort(404, 'That file no longer exists.');
        }

        // hasAssignee() is false for a null employeeId — an employee-less
        // actor never matches an unassigned task.
        $mine = $row->task->hasAssignee($actor->employeeId);
        $reviewer = $actor->mayReadAllData(ModuleCode::PROJECTS);
        if (! $reviewer && ! $mine) {
            abort(403, 'You do not have access to that.');
        }

        $path = (string) $row->getAttribute('path');
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            abort(404, 'That file no longer exists.');
        }

        return Storage::disk('local')->download($path, $row->file_name, [
            'Content-Type' => $row->mime !== '' ? $row->mime : 'application/octet-stream',
        ]);
    }
}
