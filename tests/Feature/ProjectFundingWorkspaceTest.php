<?php

declare(strict_types=1);

use App\Livewire\Projects\CashAllocations;
use App\Livewire\Projects\ProjectFundingWorkspace;
use App\Models\ProjectCashEntry;
use App\Models\ProjectContribution;
use App\Models\ProjectContributionDelivery;
use App\Modules\WorkManagement\Application\CashEntryDraft;
use App\Modules\WorkManagement\Application\Commands\RecordCashEntry;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Support\Access;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('records explicit in-kind quantity and valuation without duplicating cash and edits the opening record', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    cpaLogin($owner);
    $ui = Livewire::test(ProjectFundingWorkspace::class, ['projectId' => $project->id])->set('section', 'contributions')->call('openContribution')
        ->set('form.name', 'Venue')->set('form.party', 'Community partner')->set('form.kind', 'IN_KIND')->set('form.quantity', '2')->set('form.unit', 'days')->set('form.amount', '5000')->set('form.basis', 'Partner estimate')->call('save')->assertHasNoErrors()->assertSee('Record delivery');
    $contribution = ProjectContribution::query()->sole();
    $ui->call('openDelivery', $contribution->id)->set('form.quantity', '1')->set('form.amount', '2500')->set('form.basis', 'Documented daily rate')->call('save')->assertHasNoErrors()->assertSee('1 days');
    expect(ProjectContributionDelivery::query()->sole()->quantity_scaled)->toBe(10000)->and(ProjectCashEntry::query()->count())->toBe(0);
    $ui->call('openOpening')->set('form.date', '2026-11-01')->set('form.amount', '100')->set('form.reference', 'Opening record')->call('save')->assertHasNoErrors()->assertSee('100.00');
});

it('requires exact split totals and exposes retained allocation revisions', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $category = app(ProjectFinancials::class)->categories($actor, $project->id)->first()->id;
    $entry = app(RecordCashEntry::class)->handle($actor, $project->id, new CashEntryDraft('RECEIPT', '2026-11-01', 'Cash received', '100', $category));
    cpaLogin($owner);
    $ui = Livewire::test(CashAllocations::class, ['projectId' => $project->id, 'entryId' => $entry])->call('edit')->set('splits.0.amount', '40')->call('save')->assertHasErrors('allocations')
        ->call('add')->set('splits.1.amount', '60')->call('save')->assertSet('editing', false)->assertSee('40.00')->assertSee('60.00');
    $ui->call('edit')->set('reason', 'Consolidate classification')->set('splits.0.amount', '100')->call('remove', 1)->call('save')->assertHasNoErrors()->set('historyRevision', '1')->assertSee('40.00');
});
