<?php

declare(strict_types=1);

use App\Models\ProjectIndicator;
use App\Models\ProjectResultEntry;
use App\Modules\WorkManagement\Application\Commands\DeleteProject;
use App\Modules\WorkManagement\Application\Commands\RecordResultEntry;
use App\Modules\WorkManagement\Application\Commands\SaveIndicator;
use App\Modules\WorkManagement\Application\Commands\SaveOutcome;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\VoidResultEntry;
use App\Modules\WorkManagement\Application\IndicatorDraft;
use App\Modules\WorkManagement\Application\Queries\ProjectResults;
use App\Modules\WorkManagement\Application\ResultEntryDraft;
use App\Modules\WorkManagement\Domain\Projects\ResultValue;
use App\Support\Access;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-03T12:00:00+08:00'));
});

it('keeps results separate from task completion and records exact cumulative values with plan history', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $objective = app(SaveOutcome::class)->handle($actor, $project->id, null, 0, 'Build local capacity');
    $deliverable = app(SaveOutcome::class)->handle($actor, $project->id, null, 0, 'Training series', 'DELIVERABLE', parentId: $objective->id);
    $indicator = app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('People trained', unit: 'people', target: '100', outcomeId: $deliverable->id));
    $record = app(RecordResultEntry::class);
    $record->handle($actor, $project->id, $indicator->id, new ResultEntryDraft('2026-11-01', '20.125'));
    $record->handle($actor, $project->id, $indicator->id, new ResultEntryDraft('2026-11-02', '30.875'));
    $row = app(ProjectResults::class)->indicator($actor, $project->id, $indicator->id);
    expect($row->actual_scaled)->toBe(510000)->and($row->target_scaled)->toBe(1000000)->and($row->entry_count)->toBe(2)
        ->and($project->tasks()->count())->toBe(0)->and(ResultValue::met($row->actual_scaled, $row->target_scaled, $row->direction))->toBeFalse();
    app(SaveIndicator::class)->handle($actor, $project->id, $indicator->id, 1, new IndicatorDraft('People trained', unit: 'people', target: '80', outcomeId: $deliverable->id, reason: 'Revised cohort size'));
    $plan = ProjectIndicator::query()->sole();
    expect($plan->plan_version)->toBe(2)->and($plan->history[0]['values']['target_scaled'])->toBe(1000000)
        ->and($plan->history[0]['reason'])->toBe('Revised cohort size');
    expect(fn () => app(SaveIndicator::class)->handle($actor, $project->id, $indicator->id, 1, new IndicatorDraft('Stale', unit: 'people')))->toThrow(ValidationException::class);
});

it('uses latest stock readings and qualitative states without turning partial into fifty percent', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $stock = app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('Outstanding issues', unit: 'issues', aggregation: 'LATEST', direction: 'AT_MOST', target: '5'));
    $qualitative = app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('Policy approval', kind: 'QUALITATIVE', targetText: 'Policy adopted'));
    app(RecordResultEntry::class)->handle($actor, $project->id, $stock->id, new ResultEntryDraft('2026-11-01', '10'));
    app(RecordResultEntry::class)->handle($actor, $project->id, $stock->id, new ResultEntryDraft('2026-11-02', '3'));
    app(RecordResultEntry::class)->handle($actor, $project->id, $qualitative->id, new ResultEntryDraft('2026-11-02', valueText: 'Awaiting council approval', state: 'PARTIAL'));
    $query = app(ProjectResults::class);
    expect($query->indicator($actor, $project->id, $stock->id)->actual_scaled)->toBe(30000)
        ->and($query->indicator($actor, $project->id, $qualitative->id)->actual_scaled)->toBeNull()
        ->and($query->indicator($actor, $project->id, $qualitative->id)->actual_state)->toBe('PARTIAL')
        ->and($query->totals($actor, $project->id))->toBe(['indicators' => 2, 'planned' => 2, 'recorded' => 2, 'met' => 1, 'missing_evidence' => 0]);
});

it('keeps project participant viewer editor and unrelated access distinct for results', function () {
    $owner = cpaUser();
    $viewer = cpaUser();
    $editor = cpaUser();
    $participant = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$viewer, $editor, $participant]));
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $viewer->id, 'VIEWER');
    app(SetProjectMember::class)->handle($actor, $project->id, $editor->id, 'EDITOR');
    app(SetProjectMember::class)->handle($actor, $project->id, $participant->id, 'PARTICIPANT');
    $indicator = app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('Shared results', unit: 'items'));
    $query = app(ProjectResults::class);
    expect($query->permissions(Access::forUser($viewer), $project->id))->toBe(['view' => true, 'edit' => false])
        ->and($query->permissions(Access::forUser($editor), $project->id))->toBe(['view' => true, 'edit' => true])
        ->and($query->indicator(Access::forUser($participant), $project->id, $indicator->id))->toBeNull()
        ->and($query->totals(Access::forUser(cpaUser(allData: true)))['indicators'])->toBe(0);
    expect(fn () => app(RecordResultEntry::class)->handle(Access::forUser($viewer), $project->id, $indicator->id, new ResultEntryDraft('2026-11-02', '1')))->toThrow(HttpException::class);
    app(SetClientMember::class)->handle($actor, $project->client_id, $editor->id, false);
    expect(fn () => app(RecordResultEntry::class)->handle(Access::forUser($editor), $project->id, $indicator->id, new ResultEntryDraft('2026-11-02', '1')))->toThrow(HttpException::class);
});

it('retains corrections voids and duplicate submissions instead of changing original accomplishments', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $indicator = app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('Restored area', unit: 'hectares', target: '10', evidenceRequired: true));
    $nonce = (string) Str::ulid();
    $input = new ResultEntryDraft('2026-11-02', '8.5', issues: 'Delayed supplies', correctiveAction: 'Rescheduled delivery', requestId: $nonce);
    $record = app(RecordResultEntry::class);
    $first = $record->handle($actor, $project->id, $indicator->id, $input);
    expect($record->handle($actor, $project->id, $indicator->id, $input))->toBe($first)->and(ProjectResultEntry::query()->count())->toBe(1);
    $correction = $record->handle($actor, $project->id, $indicator->id, new ResultEntryDraft('2026-11-02', '7.5', replacesId: $first, correctionReason: 'Corrected measurement'));
    expect(ProjectResultEntry::query()->findOrFail($first)->value_scaled)->toBe(85000)
        ->and(ProjectResultEntry::query()->findOrFail($first)->void_reason)->toBe('Corrected measurement')
        ->and(app(ProjectResults::class)->indicator($actor, $project->id, $indicator->id)->actual_scaled)->toBe(75000)
        ->and(app(ProjectResults::class)->totals($actor, $project->id)['missing_evidence'])->toBe(1);
    app(VoidResultEntry::class)->handle($actor, $project->id, $correction, 'Measurement withdrawn');
    expect(ProjectResultEntry::query()->count())->toBe(2)->and(app(ProjectResults::class)->indicator($actor, $project->id, $indicator->id)->actual_scaled)->toBeNull();
    expect(fn () => app(DeleteProject::class)->handle($actor, $project->id))->toThrow(RuntimeException::class, 'accomplishments');
});

it('protects measure semantics after facts exist and keeps zero unplanned and future dates distinct', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $indicator = app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('Unplanned delivery', unit: 'items'));
    $record = app(RecordResultEntry::class);
    expect(fn () => $record->handle($actor, $project->id, $indicator->id, new ResultEntryDraft('2026-11-02')))->toThrow(ValidationException::class);
    expect(fn () => $record->handle($actor, $project->id, $indicator->id, new ResultEntryDraft('2026-11-04', '1')))->toThrow(ValidationException::class);
    $record->handle($actor, $project->id, $indicator->id, new ResultEntryDraft('2026-11-02', '0'));
    $view = app(ProjectResults::class)->indicator($actor, $project->id, $indicator->id);
    expect($view->actual_scaled)->toBe(0)->and($view->target_scaled)->toBeNull();
    expect(fn () => app(SaveIndicator::class)->handle($actor, $project->id, $indicator->id, 1, new IndicatorDraft('Unplanned delivery', unit: 'kilograms', reason: 'Different unit')))->toThrow(ValidationException::class);
});
