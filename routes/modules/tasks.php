<?php

declare(strict_types=1);

use App\Http\Controllers\TableExportController;
use App\Http\Controllers\Tasks\TaskFileController;
use App\Livewire\Tasks\MyTasksPage;
use Illuminate\Support\Facades\Route;

/*
 * TASKS — My Tasks, the assignee's queue.
 *
 * The page itself is the TASKS module's screen (config/modules.php maps
 * TASKS → /my-tasks), so the page route gates on TASKS alone.
 *
 * The file download is reachable from BOTH sides of a task — "a task moves
 * under PROJECTS for a reviewer and under TASKS for its assignee"
 * (guard.ts:80-83) — so it gates on either module, exactly like
 * GET /api/tasks/files/[id] did; the controller then narrows to the assignee
 * or a reviewer (a Projects manager, or the project's Task Leader) per row. The same controller also serves
 * `projects.task-files.download` (routes/modules/projects.php).
 */
Route::middleware(['web', 'auth', 'module:TASKS'])->group(static function (): void {
    Route::get('/my-tasks', MyTasksPage::class)->name('tasks.index');
    // The queue's CSV (TableExportController), own tasks only; literal, so
    // it never reads as a file id.
    Route::get('/my-tasks/export', TableExportController::class)
        ->defaults('table', 'tasks')
        ->name('tasks.export');
});

Route::middleware(['web', 'auth', 'module:PROJECTS,TASKS'])->group(static function (): void {
    Route::get('/my-tasks/files/{file}', [TaskFileController::class, 'show'])->name('tasks.files.download');
});
