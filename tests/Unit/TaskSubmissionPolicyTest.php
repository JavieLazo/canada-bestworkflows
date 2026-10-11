<?php

declare(strict_types=1);

use App\Domain\Projects\TaskPolicy;
use App\Modules\WorkManagement\Application\TaskFilePolicy;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;

/**
 * A task's submission mode may add moves, never change the reference ones.
 *
 * Every caller that does not pass a mode — and every GROUP task — must move
 * through exactly the reference maps (copems projects.ts:227-244), pinned
 * here as literals so an edit to the constants cannot quietly agree with
 * itself. An INDIVIDUAL task never enters SUBMITTED or RETURNED: those live on
 * each member's own response, which moves only while the task is IN_PROGRESS,
 * and the task completes only when every CURRENT assignee is APPROVED. A
 * member with no row reads the same whether the caller says null or 'NONE',
 * and an unrecognised mode or state allows nothing.
 */

/** @return list<string> */
function tspStatuses(): array
{
    return ['PENDING', 'IN_PROGRESS', 'SUBMITTED', 'RETURNED', 'COMPLETED', 'CANCELLED'];
}

/** @return list<string|null> */
function tspMemberStates(): array
{
    return [null, 'NONE', 'SUBMITTED', 'RETURNED', 'APPROVED', 'BOGUS'];
}

/**
 * copems projects.ts EMPLOYEE_TRANSITIONS, verbatim.
 *
 * @return array<string, list<string>>
 */
function tspLegacyEmployee(): array
{
    return [
        'PENDING' => ['IN_PROGRESS'],
        'IN_PROGRESS' => ['SUBMITTED'],
        'RETURNED' => ['IN_PROGRESS'],
        'SUBMITTED' => [],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];
}

/**
 * copems projects.ts MANAGER_TRANSITIONS, verbatim.
 *
 * @return array<string, list<string>>
 */
function tspLegacyManager(): array
{
    return [
        'PENDING' => ['CANCELLED'],
        'IN_PROGRESS' => ['CANCELLED'],
        'SUBMITTED' => ['COMPLETED', 'RETURNED'],
        'RETURNED' => ['CANCELLED'],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];
}

/* |---| Task-level maps |---| */

it('moves a task with no mode or GROUP exactly as the reference maps, for all 36 pairs', function () {
    $pairs = 0;
    foreach (tspStatuses() as $from) {
        foreach ([[], [null], ['GROUP']] as $mode) {
            expect(TaskPolicy::employeeCanMoveTo($from, ...$mode))->toBe(tspLegacyEmployee()[$from])
                ->and(TaskPolicy::managerCanMoveTo($from, ...$mode))->toBe(tspLegacyManager()[$from]);
        }
        foreach (tspStatuses() as $to) {
            $employee = in_array($to, tspLegacyEmployee()[$from], true);
            $manager = in_array($to, tspLegacyManager()[$from], true);
            foreach ([[], [null], ['GROUP']] as $mode) {
                expect(TaskPolicy::employeeCanMove($from, $to, ...$mode))->toBe($employee, "employee {$from}→{$to}")
                    ->and(TaskPolicy::managerCanMove($from, $to, ...$mode))->toBe($manager, "manager {$from}→{$to}");
            }
            $pairs++;
        }
    }

    expect($pairs)->toBe(36)
        ->and(TaskPolicy::EMPLOYEE_TRANSITIONS)->toBe(tspLegacyEmployee())
        ->and(TaskPolicy::MANAGER_TRANSITIONS)->toBe(tspLegacyManager())
        ->and(TaskPolicy::employeeCanMoveTo('BOGUS', 'GROUP'))->toBe([])
        ->and(TaskPolicy::managerCanMoveTo('BOGUS'))->toBe([]);
});

it('lets an INDIVIDUAL task only start, complete or be called off', function () {
    $employee = ['PENDING' => ['IN_PROGRESS']];
    $manager = ['PENDING' => ['CANCELLED'], 'IN_PROGRESS' => ['COMPLETED', 'CANCELLED']];

    foreach (tspStatuses() as $from) {
        expect(TaskPolicy::employeeCanMoveTo($from, 'INDIVIDUAL'))->toBe($employee[$from] ?? [])
            ->and(TaskPolicy::managerCanMoveTo($from, 'INDIVIDUAL'))->toBe($manager[$from] ?? []);
        foreach (tspStatuses() as $to) {
            expect(TaskPolicy::employeeCanMove($from, $to, 'INDIVIDUAL'))->toBe(in_array($to, $employee[$from] ?? [], true))
                ->and(TaskPolicy::managerCanMove($from, $to, 'INDIVIDUAL'))->toBe(in_array($to, $manager[$from] ?? [], true));
        }
    }

    // SUBMITTED and RETURNED belong to the member rows: no actor ever moves an
    // INDIVIDUAL task into either, and the maps cover all six statuses.
    $targets = array_merge(...array_values(TaskPolicy::INDIVIDUAL_EMPLOYEE_TRANSITIONS), ...array_values(TaskPolicy::INDIVIDUAL_MANAGER_TRANSITIONS));
    expect($targets)->not->toContain('SUBMITTED')
        ->and($targets)->not->toContain('RETURNED')
        ->and(array_keys(TaskPolicy::INDIVIDUAL_EMPLOYEE_TRANSITIONS))->toEqualCanonicalizing(tspStatuses())
        ->and(array_keys(TaskPolicy::INDIVIDUAL_MANAGER_TRANSITIONS))->toEqualCanonicalizing(tspStatuses());
});

it('allows no task move at all under an unrecognised mode', function (string $mode) {
    foreach (tspStatuses() as $from) {
        expect(TaskPolicy::employeeCanMoveTo($from, $mode))->toBe([])
            ->and(TaskPolicy::managerCanMoveTo($from, $mode))->toBe([]);
        foreach (tspStatuses() as $to) {
            expect(TaskPolicy::employeeCanMove($from, $to, $mode))->toBeFalse()
                ->and(TaskPolicy::managerCanMove($from, $to, $mode))->toBeFalse();
        }
    }
})->with([
    'empty' => '',
    'lower-case group' => 'group',
    'lower-case individual' => 'individual',
    'a member state' => 'NONE',
    'junk' => 'BOGUS',
]);

it('recognises exactly the two submission modes', function () {
    expect(TaskPolicy::MODES)->toBe(['GROUP' => 'One team response', 'INDIVIDUAL' => 'Each assignee submits their own response'])
        ->and(TaskPolicy::isMode('GROUP'))->toBeTrue()
        ->and(TaskPolicy::isMode('INDIVIDUAL'))->toBeTrue()
        ->and(TaskPolicy::isMode('group'))->toBeFalse()
        ->and(TaskPolicy::isMode(''))->toBeFalse()
        ->and(TaskSubmissionPolicy::isIndividual('INDIVIDUAL'))->toBeTrue()
        ->and(TaskSubmissionPolicy::isIndividual('GROUP'))->toBeFalse()
        ->and(TaskSubmissionPolicy::isIndividual(null))->toBeFalse();
});

/* |---| Member responses |---| */

it('lets a member hand in a response only from no row or a return, and only while the task is in progress', function (string $taskStatus) {
    $allowed = ['NONE' => ['SUBMITTED'], 'RETURNED' => ['SUBMITTED']];

    foreach (tspMemberStates() as $from) {
        foreach (['SUBMITTED', 'RETURNED', 'APPROVED', 'NONE'] as $to) {
            $expected = $taskStatus === 'IN_PROGRESS' && in_array($to, $allowed[$from ?? 'NONE'] ?? [], true);
            expect(TaskSubmissionPolicy::memberCanMove($taskStatus, $from, $to))->toBe($expected, ($from ?? 'null')."→{$to} on {$taskStatus}");
        }
    }
})->with(array_combine(tspStatuses(), tspStatuses()));

it('lets a reviewer approve or return a submitted response and return an approved one, only while the task is in progress', function (string $taskStatus) {
    $allowed = ['SUBMITTED' => ['APPROVED', 'RETURNED'], 'APPROVED' => ['RETURNED']];

    foreach (tspMemberStates() as $from) {
        $moves = $taskStatus === 'IN_PROGRESS' ? ($allowed[$from ?? 'NONE'] ?? []) : [];
        expect(TaskSubmissionPolicy::reviewerMovesFrom($taskStatus, $from))->toBe($moves, ($from ?? 'null')." on {$taskStatus}");
        foreach (['SUBMITTED', 'RETURNED', 'APPROVED', 'NONE'] as $to) {
            expect(TaskSubmissionPolicy::reviewerCanMove($taskStatus, $from, $to))->toBe(in_array($to, $moves, true));
        }
    }
})->with(array_combine(tspStatuses(), tspStatuses()));

it('pins the member vocabulary and its maps', function () {
    expect(TaskSubmissionPolicy::MEMBER_STATUSES)->toBe([
        'NONE' => 'Not submitted', 'SUBMITTED' => 'Submitted', 'RETURNED' => 'Returned', 'APPROVED' => 'Approved',
    ])
        // Neither side moves a SUBMITTED/APPROVED member (member) or a
        // NONE/RETURNED member (reviewer); there is no terminal reject.
        ->and(TaskSubmissionPolicy::MEMBER_TRANSITIONS['SUBMITTED'])->toBe([])
        ->and(TaskSubmissionPolicy::MEMBER_TRANSITIONS['APPROVED'])->toBe([])
        ->and(TaskSubmissionPolicy::REVIEWER_TRANSITIONS['NONE'])->toBe([])
        ->and(TaskSubmissionPolicy::REVIEWER_TRANSITIONS['RETURNED'])->toBe([])
        ->and(TaskSubmissionPolicy::REVIEWER_TRANSITIONS['APPROVED'])->toBe(['RETURNED'])
        ->and(TaskSubmissionPolicy::transitionError())->toBe('That response cannot move to this status from its current state.');
});

/* |---| Completion and progress |---| */

it('completes an INDIVIDUAL task only when every current assignee is approved', function () {
    // Nobody on the crew: refused, never completed vacuously.
    expect(TaskSubmissionPolicy::completionGaps([], []))->toBe([])
        ->and(TaskSubmissionPolicy::completeError([], []))->toBe('Assign someone before completing this task.')
        ->and(TaskSubmissionPolicy::canComplete([], ['emp-a' => 'APPROVED']))->toBeFalse();

    // A mix: no row, an explicit NONE, returned and submitted all block.
    $crew = ['emp-a', 'emp-b', 'emp-c', 'emp-d', 'emp-e'];
    $states = ['emp-a' => 'APPROVED', 'emp-c' => 'RETURNED', 'emp-d' => 'SUBMITTED', 'emp-e' => 'NONE'];
    expect(TaskSubmissionPolicy::completionGaps($crew, $states))->toBe([
        'emp-b' => 'NONE', 'emp-c' => 'RETURNED', 'emp-d' => 'SUBMITTED', 'emp-e' => 'NONE',
    ])
        ->and(TaskSubmissionPolicy::completeError($crew, $states))->toBe("Every assignee's response has to be approved before the task can be completed.")
        ->and(TaskSubmissionPolicy::canComplete($crew, $states))->toBeFalse();

    // Everyone approved.
    $approved = ['emp-a' => 'APPROVED', 'emp-b' => 'APPROVED'];
    expect(TaskSubmissionPolicy::completionGaps(['emp-a', 'emp-b'], $approved))->toBe([])
        ->and(TaskSubmissionPolicy::completeError(['emp-a', 'emp-b'], $approved))->toBeNull()
        ->and(TaskSubmissionPolicy::canComplete(['emp-a', 'emp-b'], $approved))->toBeTrue();

    // A row for someone no longer assigned neither blocks nor fills a gap.
    expect(TaskSubmissionPolicy::canComplete(['emp-a'], ['emp-a' => 'APPROVED', 'gone' => 'SUBMITTED']))->toBeTrue()
        ->and(TaskSubmissionPolicy::completionGaps(['emp-a', 'emp-b'], ['emp-a' => 'APPROVED', 'gone' => 'APPROVED']))->toBe(['emp-b' => 'NONE'])
        ->and(TaskSubmissionPolicy::canComplete(['emp-a', 'emp-b'], ['emp-a' => 'APPROVED', 'gone' => 'APPROVED']))->toBeFalse();
});

it('stores INDIVIDUAL only for a crew of two or more', function () {
    expect(TaskSubmissionPolicy::creationMode('INDIVIDUAL', 0))->toBe('GROUP')
        ->and(TaskSubmissionPolicy::creationMode('INDIVIDUAL', 1))->toBe('GROUP')
        ->and(TaskSubmissionPolicy::creationMode('INDIVIDUAL', 2))->toBe('INDIVIDUAL')
        ->and(TaskSubmissionPolicy::creationMode('INDIVIDUAL', 5))->toBe('INDIVIDUAL')
        ->and(TaskSubmissionPolicy::creationMode('GROUP', 0))->toBe('GROUP')
        ->and(TaskSubmissionPolicy::creationMode('GROUP', 2))->toBe('GROUP')
        ->and(TaskSubmissionPolicy::creationMode('BOGUS', 3))->toBe('GROUP');
});

it('summarises response progress in one line', function () {
    $mixed = TaskSubmissionPolicy::progress(3, 1, 2, 0);
    expect($mixed)->toBe(['assignees' => 3, 'approved' => 2, 'submitted' => 1, 'returned' => 0, 'not_submitted' => 0])
        ->and(TaskSubmissionPolicy::progressSentence($mixed, 'IN_PROGRESS'))->toBe('2 of 3 approved · 1 awaiting review')
        ->and(TaskSubmissionPolicy::progressSentence(TaskSubmissionPolicy::progress(4, 1, 0, 1), 'IN_PROGRESS'))->toBe('0 of 4 approved · 1 awaiting review · 1 returned · 2 not submitted')
        ->and(TaskSubmissionPolicy::progressSentence(TaskSubmissionPolicy::progress(2, 0, 2, 0), 'COMPLETED'))->toBe('2 of 2 approved')
        ->and(TaskSubmissionPolicy::progressSentence(TaskSubmissionPolicy::progress(0, 0, 0, 0), 'PENDING'))->toBe('Nobody assigned')
        // Counts that outrun the crew never make "not submitted" negative.
        ->and(TaskSubmissionPolicy::progress(1, 1, 1, 0)['not_submitted'])->toBe(0);

    // A closed task waits on nobody, so a response left submitted in it is
    // only "submitted" — never "awaiting review".
    foreach (array_diff(tspStatuses(), ['IN_PROGRESS']) as $status) {
        expect(TaskSubmissionPolicy::progressSentence(TaskSubmissionPolicy::progress(2, 1, 0, 0), $status))
            ->toBe('0 of 2 approved · 1 submitted · 1 not submitted', $status);
    }
});

it('has one response await review only while it is submitted and its task is in progress', function () {
    foreach (tspStatuses() as $status) {
        foreach (tspMemberStates() as $member) {
            expect(TaskSubmissionPolicy::responseAwaitsReview($status, $member))
                ->toBe($status === 'IN_PROGRESS' && $member === 'SUBMITTED', $status.' / '.($member ?? 'null'));
        }
    }
});

/* |---| Rules the read SQL must mirror |---| */

it('puts a task in needs-review by status, or by a submitted response on an INDIVIDUAL task in progress', function () {
    foreach ([null, 'GROUP', 'INDIVIDUAL', 'BOGUS'] as $mode) {
        foreach (tspStatuses() as $status) {
            foreach ([0, 1, 3] as $submitted) {
                // The rule any needs-review SQL must say: status = SUBMITTED,
                // OR an INDIVIDUAL task in progress with a SUBMITTED response.
                $expected = $status === 'SUBMITTED' || ($mode === 'INDIVIDUAL' && $status === 'IN_PROGRESS' && $submitted > 0);
                expect(TaskSubmissionPolicy::awaitsReview($mode, $status, $submitted))->toBe($expected)
                    ->and(TaskSubmissionPolicy::stackStatus($mode, $status, $submitted))->toBe($expected ? 'SUBMITTED' : $status);
            }
        }
    }

    // Spot checks: a GROUP task never counts member rows.
    expect(TaskSubmissionPolicy::awaitsReview('GROUP', 'IN_PROGRESS', 2))->toBeFalse()
        ->and(TaskSubmissionPolicy::awaitsReview('INDIVIDUAL', 'IN_PROGRESS', 1))->toBeTrue()
        ->and(TaskSubmissionPolicy::awaitsReview('INDIVIDUAL', 'IN_PROGRESS', 0))->toBeFalse()
        ->and(TaskSubmissionPolicy::stackStatus('INDIVIDUAL', 'IN_PROGRESS', 1))->toBe('SUBMITTED')
        ->and(TaskSubmissionPolicy::stackStatus('INDIVIDUAL', 'COMPLETED', 1))->toBe('COMPLETED');
});

it('shows an assignee their own response state only on an INDIVIDUAL task in progress, with NONE read as no row', function () {
    foreach ([null, 'GROUP', 'INDIVIDUAL', 'BOGUS'] as $mode) {
        foreach (tspStatuses() as $status) {
            foreach (tspMemberStates() as $member) {
                // As SQL would read it: a LEFT JOIN with no row gives NULL, and NULL
                // falls through to the task status.
                $joined = $member === 'NONE' ? null : $member;
                $expected = $mode === 'INDIVIDUAL' && $status === 'IN_PROGRESS' && $joined !== null ? $joined : $status;
                expect(TaskSubmissionPolicy::myStatus($mode, $status, $member))->toBe($expected);
            }
            expect(TaskSubmissionPolicy::myStatus($mode, $status, 'NONE'))->toBe(TaskSubmissionPolicy::myStatus($mode, $status, null));
        }
    }

    expect(TaskSubmissionPolicy::myStatus('INDIVIDUAL', 'IN_PROGRESS', 'NONE'))->toBe('IN_PROGRESS')
        ->and(TaskSubmissionPolicy::myStatus('INDIVIDUAL', 'IN_PROGRESS', 'RETURNED'))->toBe('RETURNED')
        ->and(TaskSubmissionPolicy::myStatus('INDIVIDUAL', 'IN_PROGRESS', 'APPROVED'))->toBe('APPROVED')
        ->and(TaskSubmissionPolicy::myStatus('INDIVIDUAL', 'COMPLETED', 'APPROVED'))->toBe('COMPLETED')
        ->and(TaskSubmissionPolicy::myStatus('GROUP', 'IN_PROGRESS', 'APPROVED'))->toBe('IN_PROGRESS');
});

it('labels and colours member and my-status values on the task tiers', function () {
    expect(array_keys(TaskSubmissionPolicy::MY_STATUSES))->toBe(['PENDING', 'IN_PROGRESS', 'SUBMITTED', 'APPROVED', 'RETURNED', 'COMPLETED', 'CANCELLED'])
        ->and(array_diff_key(TaskSubmissionPolicy::MY_STATUSES, ['APPROVED' => true]))->toBe(TaskPolicy::STATUSES)
        ->and(TaskSubmissionPolicy::myStatusLabel('APPROVED'))->toBe('Approved')
        ->and(TaskSubmissionPolicy::myStatusLabel('IN_PROGRESS'))->toBe('In Progress')
        ->and(TaskSubmissionPolicy::myStatusLabel('BOGUS'))->toBe('BOGUS')
        ->and(TaskSubmissionPolicy::myStatusPill('APPROVED'))->toBe('pill pill-success-strong')
        ->and(TaskSubmissionPolicy::memberStatusLabel(null))->toBe('Not submitted')
        ->and(TaskSubmissionPolicy::memberStatusLabel('NONE'))->toBe('Not submitted')
        ->and(TaskSubmissionPolicy::memberStatusLabel('RETURNED'))->toBe('Returned')
        ->and(TaskSubmissionPolicy::memberStatusLabel('BOGUS'))->toBe('BOGUS')
        ->and(TaskSubmissionPolicy::memberStatusPill(null))->toBe('pill pill-muted')
        ->and(TaskSubmissionPolicy::memberStatusPill('NONE'))->toBe('pill pill-muted')
        ->and(TaskSubmissionPolicy::memberStatusPill('SUBMITTED'))->toBe('pill pill-success')
        ->and(TaskSubmissionPolicy::memberStatusPill('RETURNED'))->toBe('pill pill-danger')
        ->and(TaskSubmissionPolicy::memberStatusPill('APPROVED'))->toBe('pill pill-success-strong')
        ->and(TaskSubmissionPolicy::memberStatusPill('BOGUS'))->toBe('pill pill-muted');

    foreach (tspStatuses() as $status) {
        expect(TaskSubmissionPolicy::myStatusPill($status))->toBe(TaskPolicy::statusPill($status));
    }
});

/* |---| Evidence, the mode lock and reminders |---| */

it('keeps GROUP evidence on the task rule and INDIVIDUAL evidence on the member rule', function () {
    foreach (tspStatuses() as $status) {
        foreach (tspMemberStates() as $member) {
            $group = in_array($status, ['IN_PROGRESS', 'RETURNED'], true);
            $individual = $status === 'IN_PROGRESS' && in_array($member, [null, 'NONE', 'RETURNED'], true);
            $where = "{$status} / ".($member ?? 'null');

            expect(TaskFilePolicy::submissionEditable(null, $status, $member))->toBe($group, 'null mode '.$where)
                ->and(TaskFilePolicy::submissionEditable('GROUP', $status, $member))->toBe($group, 'GROUP '.$where)
                ->and(TaskFilePolicy::submissionEditable('INDIVIDUAL', $status, $member))->toBe($individual, 'INDIVIDUAL '.$where)
                ->and(TaskFilePolicy::submissionEditable('BOGUS', $status, $member))->toBeFalse();
        }
    }
});

it('reads a GROUP hand-in from any one of its three traces', function () {
    expect(TaskSubmissionPolicy::groupHandedIn(null, null, null))->toBeFalse()
        ->and(TaskSubmissionPolicy::groupHandedIn('', '', "  \n "))->toBeFalse()
        ->and(TaskSubmissionPolicy::groupHandedIn('emp-a', null, null))->toBeTrue()
        ->and(TaskSubmissionPolicy::groupHandedIn(null, '2026-09-24 01:02:03.000', null))->toBeTrue()
        ->and(TaskSubmissionPolicy::groupHandedIn(null, new DateTimeImmutable('2026-09-24'), null))->toBeTrue()
        // A legacy row: a note with neither stamp.
        ->and(TaskSubmissionPolicy::groupHandedIn(null, null, ' Done. '))->toBeTrue();
});

it('locks the mode once anything has been handed in', function () {
    foreach (['PENDING', 'IN_PROGRESS'] as $status) {
        expect(TaskSubmissionPolicy::modeLocked($status, false, 0, false))->toBeFalse($status)
            ->and(TaskSubmissionPolicy::modeLocked($status, true, 0, false))->toBeTrue($status)
            ->and(TaskSubmissionPolicy::modeLocked($status, false, 1, false))->toBeTrue($status)
            ->and(TaskSubmissionPolicy::modeLocked($status, false, 0, true))->toBeTrue($status);
    }
});

it('locks the mode on a handed-in, closed or unrecognised status even with no other trace', function (string $status) {
    // A legacy GROUP row returned with a null note keeps no submitter, stamp
    // or note; its status is the only trace, and INDIVIDUAL has no move from it.
    expect(TaskSubmissionPolicy::modeLocked($status, false, 0, false))->toBeTrue();
})->with(['SUBMITTED', 'RETURNED', 'COMPLETED', 'CANCELLED', 'BOGUS']);

it('stops owing work once a response is submitted or approved, or the task closes', function () {
    foreach (['SUBMITTED', 'APPROVED'] as $member) {
        expect(TaskSubmissionPolicy::memberOwesWork('INDIVIDUAL', 'IN_PROGRESS', $member))->toBeFalse();
    }
    foreach ([null, 'NONE', 'RETURNED'] as $member) {
        expect(TaskSubmissionPolicy::memberOwesWork('INDIVIDUAL', 'IN_PROGRESS', $member))->toBeTrue();
    }
    foreach ([null, 'GROUP', 'INDIVIDUAL'] as $mode) {
        expect(TaskSubmissionPolicy::memberOwesWork($mode, 'COMPLETED', null))->toBeFalse()
            ->and(TaskSubmissionPolicy::memberOwesWork($mode, 'CANCELLED', 'RETURNED'))->toBeFalse()
            ->and(TaskSubmissionPolicy::memberOwesWork($mode, 'PENDING', null))->toBeTrue();
    }

    // GROUP follows the task: a member row never matters, a hand-in does.
    expect(TaskSubmissionPolicy::memberOwesWork('GROUP', 'IN_PROGRESS', 'APPROVED'))->toBeTrue()
        ->and(TaskSubmissionPolicy::memberOwesWork('GROUP', 'RETURNED', null))->toBeTrue()
        ->and(TaskSubmissionPolicy::memberOwesWork('GROUP', 'SUBMITTED', null))->toBeFalse();
});
