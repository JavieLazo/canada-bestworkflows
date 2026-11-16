<?php

declare(strict_types=1);

use App\Http\Controllers\Projects\FinanceExportController;
use App\Http\Controllers\Projects\ProjectsExportController;
use App\Http\Controllers\Projects\RecordFileController;
use App\Http\Controllers\Projects\ReportWorkbookController;
use App\Http\Controllers\Projects\TasksExportController;
use App\Http\Controllers\Tasks\TaskFileController;
use App\Livewire\Projects\ClientsPage;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\PortfoliosPage;
use App\Livewire\Projects\Show;
use Illuminate\Support\Facades\Route;

/*
 * PROJECT MANAGEMENT — the port of src/app/(protected)/projects.
 *
 * The pages gate on PROJECTS alone, exactly as the reference layout does
 * (projects/layout.tsx → requirePageModule("PROJECTS")). Task-file downloads
 * gate on PROJECTS *or* TASKS, because GET /api/tasks/files/[id] served both
 * the reviewer and the assignee — "a task moves under PROJECTS for a reviewer
 * and under TASKS for its assignee" (guard.ts:80-83); the controller then
 * narrows to those parties — the project's Task Leader counts as a reviewer.
 * It is the same controller My Tasks uses for `tasks.files.download`
 * (routes/modules/tasks.php): two URLs, both linked from Blade, one rule.
 *
 * Order matters: the literal segments (/projects/export, /projects/task-files)
 * are registered before /projects/{project} so the wildcard cannot swallow
 * them.
 */
Route::middleware(['web', 'auth', 'module:PROJECTS,TASKS'])->group(static function (): void {
    Route::get('/projects/task-files/{file}', [TaskFileController::class, 'show'])
        ->name('projects.task-files.download');
});

Route::middleware(['web', 'auth', 'module:PROJECTS'])->group(static function (): void {
    Route::get('/projects/export', ProjectsExportController::class)->name('projects.export');
    Route::get('/projects/record-files/{file}', RecordFileController::class)->name('projects.record-files.show');
    Route::get('/projects', Index::class)->name('projects.index');
    Route::get('/projects/clients', ClientsPage::class)->name('projects.clients');
    Route::get('/projects/portfolios', PortfoliosPage::class)->name('projects.portfolios');
    Route::get('/projects/{project}/tasks/export', TasksExportController::class)
        ->name('projects.tasks.export');
    Route::get('/projects/{project}/finance/export', FinanceExportController::class)->name('projects.finance.export');
    Route::get('/projects/{project}/reports/{report}/workbook', ReportWorkbookController::class)->name('projects.reports.workbook');
    Route::get('/projects/{project}', Show::class)->name('projects.show');
});
