<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\ProjectTask;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Support\Access;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One task attachment, as a download — the port of `GET /api/tasks/files/[id]`
 * (src/app/api/tasks/files/[id]/route.ts).
 *
 * ONE controller behind TWO routes, on purpose: `tasks.files.download`
 * (`/my-tasks/files/{file}`, linked from My Tasks) and
 * `projects.task-files.download` (`/projects/task-files/{file}`, linked from
 * the project dialog). Both are gated `module:PROJECTS,TASKS`, because "a
 * task moves under PROJECTS for a reviewer and under TASKS for its assignee"
 * (guard.ts:80-83); the routes stay separate so neither URL changes, and the
 * one rule below narrows either of them to exactly those two parties.
 *
 * Reading a file takes being on the task — one of its assignees (the task is
 * shared; membership is the `project_task_assignees` pivot, read through
 * {@see ProjectTask::hasAssignee()}), or someone who runs its project: a
 * reviewer (all_data + PROJECTS) or the project's Task Leader
 * (WorkRules::runsProject()).
 * On an INDIVIDUAL task a member's SUBMISSION evidence is narrower still: it
 * is that member's and the task's reviewer's alone, so a co-assignee gets a
 * 403 — even one holding all_data + PROJECTS, because being on the crew makes
 * them a member there, not a reviewer. WorkRecords::download() applies both
 * rules for both routes.
 *
 * The bytes live on the filesystem, not in a base64 column as in the
 * reference, so a disk stream stands in for the data-URL decoding half of
 * the reference (`fileResponse`); the `attachment` disposition and the
 * stored filename match the reference.
 */
final class TaskFileController extends Controller
{
    public function show(string $file): StreamedResponse
    {
        return app(WorkRecords::class)->download(app(Access::class), $file);
    }
}
