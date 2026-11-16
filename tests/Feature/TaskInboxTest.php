<?php

declare(strict_types=1);

use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Domain\Tasks\TaskInbox;
use App\Support\Access;
use App\Support\TextSort;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

afterEach(fn () => CarbonImmutable::setTestNow());

it('keeps attention order and queue counts aligned with the assignees own response', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 01:00:00', 'UTC'));
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker]));
    foreach (['PENDING', 'IN_PROGRESS', 'RETURNED', 'SUBMITTED', 'COMPLETED', 'CANCELLED'] as $status) {
        foreach ([[null, null], [null, '2026-10-30'], ['2026-11-01', '2026-11-20'], ['2026-11-10', '2026-11-20']] as [$start, $due]) {
            cpaTask($owner, $project, [$employee], title: $status.' '.($start ?? $due ?? 'undated'))->update(['status' => $status, 'start_date' => $start, 'due_date' => $due]);
        }
    }
    $second = cpaEmployee('Peer');
    $peer = cpaUser($second, ['TASKS']);
    app(SetClientMember::class)->handle(Access::forUser($owner), $project->client_id, $peer->id, true);
    $individual = cpaTask($owner, $project, [$employee, $second], 'INDIVIDUAL', 'My approved response');
    $individual->update(['status' => 'IN_PROGRESS', 'due_date' => '2026-10-30']);
    $response = new ProjectTaskSubmission;
    $response->forceFill(['task_id' => $individual->id, 'employee_id' => $employee->id, 'status' => 'APPROVED', 'remarks' => 'My own private response', 'submitted_at' => CarbonImmutable::now(), 'revision' => 1])->save();
    $actor = Access::forUser($worker);
    $records = app(WorkRecords::class);
    $filters = ['q' => '', 'status' => 'ALL', 'project' => 'ALL'];
    $rows = $records->ownPage($actor, $filters, 'focus', 'asc', 1, 100, '2026-11-01');
    $expected = $rows->all();
    usort($expected, static fn ($a, $b): int => (TaskInbox::RANKS[$a->inboxBucket('2026-11-01')] <=> TaskInbox::RANKS[$b->inboxBucket('2026-11-01')]) ?: TextSort::compare($a->due_date?->format('Y-m-d'), $b->due_date?->format('Y-m-d')) ?: strcmp($a->code, $b->code));
    expect($rows->pluck('id')->all())->toBe(array_map(static fn ($row): string => $row->id, $expected));
    expect($rows->first()->inboxBucket('2026-11-01'))->toBe('OVERDUE');
    expect(TaskInbox::bucket('IN_PROGRESS', '2026-11-10', '2026-11-20', '2026-11-01'))->toBe('IN_PROGRESS');
    expect(TaskInbox::RANKS['IN_PROGRESS'])->toBeLessThan(TaskInbox::RANKS['TODAY']);
    $summary = $records->ownSummary($actor, '2026-11-01');
    foreach (TaskInbox::QUEUES as $queue => $label) {
        $states = TaskInbox::statuses($queue);
        $matching = $rows->filter(static fn ($row): bool => ($states === [] || in_array($row->my_status, $states, true)) && ($queue !== 'OVERDUE' || TaskInbox::overdue($row->my_status, $row->due_date?->format('Y-m-d'), '2026-11-01')));
        $page = $records->ownPage($actor, $filters + ['queue' => $queue], 'focus', 'asc', 1, 100, '2026-11-01');
        expect($page->pluck('id')->all())->toBe($matching->pluck('id')->all())->and($summary['queues'][$queue])->toBe($matching->count());
    }
    $approved = $rows->firstWhere('id', $individual->id);
    expect($approved->my_status)->toBe('APPROVED')->and($approved->inboxBucket('2026-11-01'))->toBe('WAITING')->and($approved->ownAction())->toBe('view');
});

it('exports the selected personal queue and sort through the same read path', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 01:00:00', 'UTC'));
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker]));
    $first = cpaTask($owner, $project, [$employee], title: 'Returned export');
    $first->update(['status' => 'RETURNED']);
    cpaTask($owner, $project, [$employee], title: 'Pending outside export');
    cpaLogin($worker);
    $csv = $this->get(route('tasks.export', ['queue' => 'RETURNED', 'sort' => 'focus']))->assertOk()->streamedContent();
    expect($csv)->toContain('Returned export')->not->toContain('Pending outside export');
});
