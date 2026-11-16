<?php

declare(strict_types=1);

use App\Livewire\Projects\WorkbookImport;
use App\Models\ProjectResultEntry;
use App\Modules\WorkManagement\Application\Commands\SaveIndicator;
use App\Modules\WorkManagement\Application\IndicatorDraft;
use App\Platform\Transfer\XlsxWorkbook;
use App\Support\Access;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('imports a mapped XLSX through the project dialog and leaves bad rows visible', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $project = cpaProject($owner, cpaClient($owner));
    $indicator = app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('Verified sites', unit: 'sites'));
    $bytes = app(XlsxWorkbook::class)->bytes([['name' => 'Accomplishments', 'title' => 'Accomplishments', 'context' => '', 'columns' => ['indicator' => ['label' => 'Indicator ID', 'type' => 'TEXT', 'total' => false], 'date' => ['label' => 'Actual date', 'type' => 'TEXT', 'total' => false], 'actual' => ['label' => 'Numeric actual', 'type' => 'TEXT', 'total' => false]], 'rows' => [['indicator' => $indicator->id, 'date' => '2026-11-02', 'actual' => '3']]]]);
    cpaLogin($owner);
    Livewire::test(WorkbookImport::class, ['projectId' => $project->id])->set('open', true)->set('upload', UploadedFile::fake()->createWithContent('actuals.xlsx', $bytes))
        ->call('mapColumns')->assertHasNoErrors()->call('preview')->assertHasNoErrors()->assertSet('result.created', 1)->call('import')->assertHasNoErrors()->assertSet('result.saved', true);
    expect(ProjectResultEntry::query()->sole()->value_scaled)->toBe(30000);
});
