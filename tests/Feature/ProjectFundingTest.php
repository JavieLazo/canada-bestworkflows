<?php

declare(strict_types=1);

use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashAllocation;
use App\Models\ProjectCashEntry;
use App\Models\ProjectCostCategory;
use App\Models\ProjectRecordValue;
use App\Modules\WorkManagement\Application\BudgetLineDraft;
use App\Modules\WorkManagement\Application\CashEntryDraft;
use App\Modules\WorkManagement\Application\Commands\ApproveProjectBudget;
use App\Modules\WorkManagement\Application\Commands\RecordAllocatedCash;
use App\Modules\WorkManagement\Application\Commands\RecordCashEntry;
use App\Modules\WorkManagement\Application\Commands\RecordContributionDelivery;
use App\Modules\WorkManagement\Application\Commands\SaveBudgetLine;
use App\Modules\WorkManagement\Application\Commands\SaveContribution;
use App\Modules\WorkManagement\Application\Commands\SaveFunding;
use App\Modules\WorkManagement\Application\Commands\SaveFundingInstallment;
use App\Modules\WorkManagement\Application\Commands\SaveOpeningCash;
use App\Modules\WorkManagement\Application\Commands\SaveProjectReport;
use App\Modules\WorkManagement\Application\Commands\SaveRecordValues;
use App\Modules\WorkManagement\Application\Commands\SaveReportField;
use App\Modules\WorkManagement\Application\Commands\SaveReportingPeriod;
use App\Modules\WorkManagement\Application\Commands\SaveReportTemplate;
use App\Modules\WorkManagement\Application\Commands\SetCashAllocations;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Commands\StartProjectBudget;
use App\Modules\WorkManagement\Application\Queries\ProjectFunding;
use App\Modules\WorkManagement\Application\Queries\ProjectFundingSummary;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Support\Access;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-03T12:00:00+08:00'));
});

it('splits cash once across funding and counterpart cash without duplicating receipts or inventing in-kind cash', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $funding = app(SaveFunding::class)->handle($actor, $project->id, null, 0, 'General funding', 'Partner', '100');
    app(SaveFundingInstallment::class)->handle($actor, $project->id, $funding->id, null, 0, 'First installment', '40');
    $counterpart = app(SaveContribution::class)->handle($actor, $project->id, null, 0, 'Cash counterpart', 'Local partner', 'CASH', '100');
    $inkind = app(SaveContribution::class)->handle($actor, $project->id, null, 0, 'Venue support', 'School', 'IN_KIND', '500', '5', 'days', 'Agreed daily venue rate');
    $category = ProjectCostCategory::query()->sole();
    $receipt = app(RecordCashEntry::class)->handle($actor, $project->id, new CashEntryDraft('RECEIPT', '2026-11-02', 'Received funding', '100', $category->id));
    app(SetCashAllocations::class)->handle($actor, $project->id, $receipt, 0, [
        ['amount' => '40', 'sourceKind' => 'FUNDING', 'sourceId' => $funding->id], ['amount' => '60', 'sourceKind' => 'CONTRIBUTION', 'sourceId' => $counterpart->id],
    ]);
    app(RecordContributionDelivery::class)->handle($actor, $project->id, $inkind->id, '2026-11-02', '2', '200', 'Agreed daily venue rate');
    $summary = app(ProjectFundingSummary::class)->totals($actor, $project->id)[0];
    expect($summary['series']['FUNDING']['received'])->toBe(4000)->and($summary['series']['CASH']['received'])->toBe(6000)
        ->and($summary['series']['IN_KIND']['received'])->toBe(20000)->and($summary['unlinked'])->toBe(0)
        ->and(app(ProjectFundingSummary::class)->totals(Access::forUser(cpaUser(allData: true))))->toBe([]);
    $query = app(ProjectFunding::class);
    expect($query->fundings($actor, $project->id)->first()->received_minor)->toBe(4000)
        ->and($query->contributions($actor, $project->id)->firstWhere('id', $counterpart->id)->received_minor)->toBe(6000)
        ->and((int) ProjectCashEntry::query()->sum('amount_minor'))->toBe(10000)
        ->and($query->cashPosition($actor, $project->id))->toBeNull();
    app(SaveOpeningCash::class)->handle($actor, $project->id, 0, '2026-11-01', '1000', 'Opening statement');
    expect($query->cashPosition($actor, $project->id))->toBe(110000);
    $period = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'November', '2026-11-01', '2026-11-30');
    $template = app(SaveReportTemplate::class)->handle($actor, $project->id, null, 0, 'Financial', 'FINANCIAL', ['sections' => ['profile', 'cash'], 'fields' => []]);
    $report = app(SaveProjectReport::class)->handle($actor, $project->id, null, 0, $template->id, $period->id, 'Financial report', 'Recorded cash');
    $snapshot = app(ProjectReports::class)->preview($actor, $project->id, $report->id);
    expect($snapshot['period_opening_minor'])->toBe(100000)->and($snapshot['period_variance']['received_minor'])->toBe(10000)
        ->and($snapshot['recorded_cash_position_minor'])->toBe(110000)->and($snapshot['period_variance']['planned_minor'])->toBeNull();
    $futurePeriod = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'Next year', '2027-01-01', '2027-12-31');
    $futureReport = app(SaveProjectReport::class)->handle($actor, $project->id, null, 0, $template->id, $futurePeriod->id, 'Future plan', 'Planned work');
    expect(app(ProjectReports::class)->preview($actor, $project->id, $futureReport->id)['period_opening_minor'])->toBeNull();
    expect(fn () => app(SetCashAllocations::class)->handle($actor, $project->id, $receipt, 1, [['amount' => '90']], 'Incorrect sum'))->toThrow(ValidationException::class);
    app(SetCashAllocations::class)->handle($actor, $project->id, $receipt, 1, [['amount' => '100', 'sourceKind' => 'FUNDING', 'sourceId' => $funding->id]], 'Correct source classification');
    expect(ProjectCashAllocation::query()->count())->toBe(3)->and($query->fundings($actor, $project->id)->first()->received_minor)->toBe(10000);
});

it('carries typed budget answers into a new revision while retaining the approved original', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $project = cpaProject($owner, cpaClient($owner));
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $field = app(SaveReportField::class)->handle($actor, $project->id, null, 0, 'Matching value', 'BUDGET_LINE', 'MONEY');
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('Cost', ProjectCostCategory::query()->sole()->id, '10'));
    $line = ProjectBudgetLine::query()->sole();
    $answers = app(ProjectReports::class)->answers($actor, $project->id, 'BUDGET_LINE', $line->id, true);
    app(SaveRecordValues::class)->handle($actor, $project->id, 'BUDGET_LINE', $line->id, $answers['signature'], [$field->id => '25.50']);
    app(ApproveProjectBudget::class)->handle($actor, $project->id, $budget, 3);
    $next = app(StartProjectBudget::class)->handle($actor, $project->id, 'Next reporting period');
    $copy = ProjectBudgetLine::query()->where('budget_id', $next)->sole();
    expect(ProjectRecordValue::query()->where('record_id', $copy->id)->sole()->value)->toBe(['value' => 2550])
        ->and(ProjectRecordValue::query()->where('record_id', $line->id)->sole()->value)->toBe(['value' => 2550]);
});

it('keeps sourced cash retry-safe and preserves approved period and funding links for old callers', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $funding = app(SaveFunding::class)->handle($actor, $project->id, null, 0, 'Funding', 'Partner', '100');
    $period = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'Current period', '2026-11-01', '2026-11-30');
    $category = ProjectCostCategory::query()->sole();
    $nonce = (string) Str::ulid();
    $input = new CashEntryDraft('RECEIPT', '2026-11-02', 'Receipt', '25', $category->id, requestId: $nonce);
    $record = app(RecordAllocatedCash::class);
    $record->handle($actor, $project->id, $input, 'FUNDING', $funding->id);
    $record->handle($actor, $project->id, $input, 'FUNDING', $funding->id);
    expect(ProjectCashEntry::query()->count())->toBe(1)->and(ProjectCashAllocation::query()->count())->toBe(1);
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('Cost', $category->id, '10', reporting: ['periodId' => $period->id, 'sourceKind' => 'FUNDING', 'sourceId' => $funding->id]));
    $line = ProjectBudgetLine::query()->sole();
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 2, $line->id, new BudgetLineDraft('Revised cost', $category->id, '12'));
    expect($line->fresh()->period_id)->toBe($period->id)->and($line->fresh()->funding_id)->toBe($funding->id);
    app(ApproveProjectBudget::class)->handle($actor, $project->id, $budget, 3);
    expect(fn () => app(SaveReportingPeriod::class)->handle($actor, $project->id, $period->id, 1, 'Changed', '2026-10-01', '2026-11-30', reason: 'New dates'))->toThrow(ValidationException::class);
});
