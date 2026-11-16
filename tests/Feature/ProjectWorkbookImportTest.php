<?php

declare(strict_types=1);

use App\Models\ProjectBudgetLine;
use App\Models\ProjectResultEntry;
use App\Modules\WorkManagement\Application\Commands\SaveIndicator;
use App\Modules\WorkManagement\Application\Commands\SaveProjectReport;
use App\Modules\WorkManagement\Application\Commands\SaveReportingPeriod;
use App\Modules\WorkManagement\Application\Commands\SaveReportTemplate;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Commands\StartProjectBudget;
use App\Modules\WorkManagement\Application\IndicatorDraft;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Modules\WorkManagement\Application\Queries\ProjectReportWorkbooks;
use App\Modules\WorkManagement\Application\WorkbookTransfer;
use App\Platform\Transfer\CsvSheet;
use App\Platform\Transfer\XlsxReader;
use App\Support\Access;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('previews without writes imports canonical actuals once and refuses a changed preview', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $indicator = app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('Sites', unit: 'sites'));
    $sheet = new CsvSheet(['Indicator', 'Date', 'Actual'], [['line' => 4, 'cells' => [$indicator->id, '2026-11-02', '2']]]);
    $map = ['indicator' => '0', 'date' => '1', 'value' => '2'];
    $transfer = app(WorkbookTransfer::class);
    $preview = $transfer->run($actor, $project->id, 'RESULT_ENTRY', $sheet, [], $map);
    expect($preview['report']->created)->toBe(1)->and(ProjectResultEntry::query()->count())->toBe(0);
    $commit = $transfer->run($actor, $project->id, 'RESULT_ENTRY', $sheet, [], $map, $preview['stamp']);
    expect($commit['report']->saved)->toBeTrue()->and(ProjectResultEntry::query()->sole()->value_scaled)->toBe(20000);
    $retry = $transfer->run($actor, $project->id, 'RESULT_ENTRY', $sheet, [], $map);
    expect($retry['report']->unchanged)->toBe(1);
    $changed = new CsvSheet($sheet->headers, [['line' => 4, 'cells' => [$indicator->id, '2026-11-02', '3']]]);
    expect(fn () => $transfer->run($actor, $project->id, 'RESULT_ENTRY', $changed, [], $map, $retry['stamp']))->toThrow(ValidationException::class);
    expect(ProjectResultEntry::query()->count())->toBe(1);
});

it('rejects the entire batch with a foreign reference and recalculates imported budget assumptions', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $project = cpaProject($owner, cpaClient($owner));
    $other = cpaProject($owner, cpaClient($owner));
    $foreign = app(SaveIndicator::class)->handle($actor, $other->id, null, 0, new IndicatorDraft('Foreign', unit: 'sites'));
    $own = app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('Own', unit: 'sites'));
    $sheet = new CsvSheet(['Indicator', 'Date', 'Actual'], [['line' => 2, 'cells' => [$own->id, '2026-11-02', '1']], ['line' => 3, 'cells' => [$foreign->id, '2026-11-02', '1']]]);
    $report = app(WorkbookTransfer::class)->run($actor, $project->id, 'RESULT_ENTRY', $sheet, [], ['indicator' => '0', 'date' => '1', 'value' => '2'])['report'];
    expect($report->failed)->toBe(1)->and(ProjectResultEntry::query()->count())->toBe(0);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    $category = app(ProjectFinancials::class)->categories($actor, $project->id)->first()->id;
    $sheet = new CsvSheet(['Item', 'Quantity', 'Frequency', 'Unit', 'Amount', 'Basis'], [['line' => 5, 'cells' => ['Travel', '2.5', '3', '10.01', '9999999', 'CALCULATED']]]);
    $map = ['label' => '0', 'quantity' => '1', 'frequency' => '2', 'unit' => '3', 'amount' => '4', 'basis' => '5'];
    $options = ['budgetId' => $budget, 'categoryId' => $category];
    $transfer = app(WorkbookTransfer::class);
    $preview = $transfer->run($actor, $project->id, 'BUDGET_LINE', $sheet, $options, $map);
    expect($preview['report']->errors)->toBe([]);
    $saved = $transfer->run($actor, $project->id, 'BUDGET_LINE', $sheet, $options, $map, $preview['stamp']);
    expect($saved['report']->saved)->toBeTrue()->and(ProjectBudgetLine::query()->sole()->total_minor)->toBe(7508);
    $period = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'November', '2026-11-01', '2026-11-30');
    $template = app(SaveReportTemplate::class)->handle($actor, $project->id, null, 0, 'Work and budget', 'WORK_PLAN', ['sections' => ['profile', 'budget'], 'fields' => []]);
    $draft = app(SaveProjectReport::class)->handle($actor, $project->id, null, 0, $template->id, $period->id, 'Work and budget plan', 'Proposed costs', budgetId: $budget);
    $preview = app(ProjectReports::class)->preview($actor, $project->id, $draft->id);
    expect($preview['budget_baseline']['status'])->toBe('DRAFT')->and($preview['budget_baseline']['total_minor'])->toBe(7508)
        ->and($preview['coverage']['blocking'])->toContain('Approve the selected budget before submitting this report.');
    $bytes = app(ProjectReportWorkbooks::class)->download($actor, $project->id, $draft->id)->getContent();
    $path = tempnam(sys_get_temp_dir(), 'draft-budget-');
    try {
        file_put_contents($path, $bytes);
        $read = app(XlsxReader::class)->read($path, 'Budget', 4)['sheet'];
        $cells = $read->rows[0]['cells'];
        $cells[5] = '4';
        $edited = new CsvSheet($read->headers, [['line' => 5, 'cells' => array_values($cells)]]);
        $map = ['id' => '0', 'label' => '1', 'category' => '2', 'basis' => '4', 'quantity' => '5', 'frequency' => '6', 'unit' => '7', 'amount' => '12'];
        $preview = $transfer->run($actor, $project->id, 'BUDGET_LINE', $edited, $options, $map);
        $saved = $transfer->run($actor, $project->id, 'BUDGET_LINE', $edited, $options, $map, $preview['stamp']);
        expect($saved['report']->updated)->toBe(1)->and(ProjectBudgetLine::query()->count())->toBe(1)
            ->and(ProjectBudgetLine::query()->sole()->total_minor)->toBe(12012);
    } finally {
        unlink($path);
    }
});
