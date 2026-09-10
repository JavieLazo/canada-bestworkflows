<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tasks;

use App\Domain\Enums\ModuleCode;
use App\Http\Controllers\Controller;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Support\Access;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /my-tasks/files/{file} — one task attachment, as a download.
 *
 * The port of `GET /api/tasks/files/[id]` (src/app/api/tasks/files/[id]/
 * route.ts): reading a file takes being on the task — one of its assignees
 * (the task is shared; membership is the `project_task_assignees` pivot, read
 * through {@see ProjectTask::hasAssignee()}), or a reviewer
 * (all_data + PROJECTS). The bytes moved from a base64 column to the
 * filesystem in this port, so the data-URL decoding half of the reference
 * (`fileResponse`) is replaced by a disk stream; the `attachment` disposition
 * and the stored filename survive.
 */
final class TaskFileController extends Controller
{
    public function show(string $file): StreamedResponse
    {
        $access = app(Access::class);

        $row = ProjectTaskFile::query()->with('task')->whereKey($file)->first();
        abort_if($row === null || $row->task === null, 404, 'That file was not found.');

        // The widening gate is PROJECTS alone — a TASKS holder carrying
        // all_data still only reads files on their own tasks (guard.ts:97-99).
        $reviewer = $access->mayReadAllData(ModuleCode::PROJECTS);
        // The link guard comes first: ownEmployeeId() throws without one, and
        // hasAssignee() is false for null regardless — an unlinked account can
        // never match "unassigned".
        $mine = $access->hasEmployeeLink()
            && $row->task->hasAssignee($access->ownEmployeeId());
        abort_unless($reviewer || $mine, 403);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($row->path), 404, 'That file was not found.');

        // `attachment` rather than `inline`: these are uploads of unknown
        // provenance, and a browser must save them, not render them.
        return $disk->download($row->path, $row->file_name, [
            'Content-Type' => $row->mime !== '' ? $row->mime : 'application/octet-stream',
        ]);
    }
}
