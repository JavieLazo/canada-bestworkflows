<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskSubmission;
use App\Models\TaskComment;
use App\Models\WorkSite;

/**
 * Two projects with thirteen tasks: the twelve of seed.ts buildProjects()
 * (prj-1 and prj-2) plus one this port adds (TSK-013, below). The demo
 * Employee account is linked to emp-4, so its tasks deliberately cover every
 * TASK status My Tasks can show: pending, in progress, submitted, returned
 * and completed (plus a cancelled one on the reviewing side). Approved, the
 * one state that belongs to a member's own response on an INDIVIDUAL task,
 * is not seeded for emp-4: walking TSK-013 through a review reaches it.
 *
 * Assignees live in the project_task_assignees pivot (a task is SHARED by
 * 0..N employees — ProjectTask docblock), written through
 * ProjectTask::syncAssignees() so a re-run is a no-op for rows already there.
 * TSK-002 is deliberately given TWO assignees (emp-6 and the demo emp-4) so
 * the demo data shows collaboration on first run; the other reference tasks
 * keep the reference's single owner. They are all GROUP tasks, the column
 * default: a submitted task names its first assignee as submitted_by,
 * matching the "first to submit submits for everyone" rule.
 *
 * TSK-013 is the INDIVIDUAL demo, which the reference cannot have (copems has
 * no submission mode): three assignees answer for themselves, and the task
 * stays IN_PROGRESS while their responses move. emp-5's is APPROVED, emp-6's
 * RETURNED with a note, and the demo emp-4 has none yet (Not submitted), so a
 * walkthrough can submit from My Tasks and then review from Projects. The
 * responses are project_task_submissions rows keyed on (task_id, employee_id),
 * each seeded in the state one submission and one review leave behind. The
 * crew leaves out emp-1 and emp-3 ON PURPOSE: their accounts (admin,
 * supervisor) are the all-data PROJECTS reviewers, and an assignee may never
 * review their own task. emp-5 and emp-6 have no account, so emp-6 can never
 * resubmit: completing the task takes emp-4's approval AND emp-6's removal —
 * the situation a crew member who cannot sign in creates.
 *
 * A re-run restores all of that — the task row, the crew, both responses and
 * emp-4's missing one — so the walkthrough can be repeated; files and
 * notifications it left behind stay. TSK-013 is seeded only while that code
 * is free or already the seeder's own (id tsk-13): on a database whose
 * seeded tasks stop at TSK-012, CreateTask mints TSK-013 next, and a
 * developer's task holding it is NEVER taken over.
 *
 * Also the rework conversation on TSK-004, so the thread has something to
 * show and the two-way shape is obvious on first run, and the four task
 * notifications that keep the bell from being empty (ntf-1/2/4/5; the leave
 * one is seeded with the leave requests).
 *
 * A submission lands a few days after the task was handed out, and the review
 * a day after that — never before the task existed.
 */
final class ProjectSeeder extends PortSeeder
{
    public function run(): void
    {
        $employees = $this->employeeIdsByIndex();
        $adminId = $this->userId('admin@bestworkflows.local');
        $supervisorId = $this->userId('supervisor@bestworkflows.local');
        $employeeAccountId = $this->userId('employee@bestworkflows.local');

        $site1 = $this->seedId(WorkSite::class, 'site-1');

        $prj1 = $this->upsert(Project::class, ['code' => 'PRJ-001'], [
            'id' => 'prj-1',
            'name' => 'Makati HQ Security Upgrade',
            'description' => 'Replace perimeter cameras and badge readers across the head office, and rewrite the access-control policy that governs them.',
            'site_id' => $site1,
            'status' => 'ACTIVE',
            'start_date' => $this->dateKey(60),
            'due_date' => $this->dateKey(-20),
            'manager_id' => $supervisorId,
            'created_at' => $this->at(62, 9, 0),
        ]);

        $prj2 = $this->upsert(Project::class, ['code' => 'PRJ-002'], [
            'id' => 'prj-2',
            'name' => 'Q3 Payroll System Audit',
            'description' => "Reconcile the previous quarter's payroll registers against the statutory deduction tables and document every variance.",
            'site_id' => $site1,
            'status' => 'ACTIVE',
            'start_date' => $this->dateKey(30),
            'due_date' => $this->dateKey(-15),
            'manager_id' => $adminId,
            'created_at' => $this->at(32, 9, 0),
        ]);

        $projects = [
            1 => ['id' => (string) $prj1->getKey(), 'manager' => $supervisorId],
            2 => ['id' => (string) $prj2->getKey(), 'manager' => $adminId],
        ];

        // id, code, project, title, description, employee indexes (the
        // reference's single owner, plus co-assignees), status, priority,
        // due (days ago; negative = future), opened (days ago), remarks,
        // review remarks
        $specs = [
            ['tsk-1', 'TSK-001', 1, 'Survey perimeter gates A and B', 'Walk both gates, photograph every mount point and note the cabling runs.', [3], 'COMPLETED', 'HIGH', 40, 55, 'Survey done, 14 mount points logged with photos.', 'Complete and well documented.'],
            ['tsk-2', 'TSK-002', 1, 'Install CCTV at the loading bay', 'Mount and commission the four bay cameras, then verify the night-vision feed.', [5, 3], 'IN_PROGRESS', 'HIGH', -6, 20, null, null],
            ['tsk-3', 'TSK-003', 1, 'Draft the access-control policy', 'Write the badge issuance, revocation and visitor escort rules for review.', [3], 'SUBMITTED', 'NORMAL', -3, 14, 'First draft attached — the visitor escort section still needs HR sign-off.', null],
            ['tsk-4', 'TSK-004', 1, 'Replace the lobby badge readers', 'Swap both lobby readers for the new model and re-enrol the existing badges.', [3], 'RETURNED', 'URGENT', 2, 18, 'Readers swapped, badges re-enrolled.', 'The east reader still rejects contractor badges — please retest and resubmit.'],
            ['tsk-5', 'TSK-005', 1, 'Write up the night patrol schedule', 'Draft a two-guard rotation covering 22:00 to 06:00, seven days.', [4], 'PENDING', 'NORMAL', -12, 4, null, null],
            ['tsk-6', 'TSK-006', 1, 'Decommission the old alarm panel', 'Remove the legacy panel once the new system passes acceptance.', [5], 'CANCELLED', 'LOW', -30, 16, null, 'Panel stays in place as the fallback — cancelled by the client.'],
            ['tsk-7', 'TSK-007', 1, 'Test the emergency exit alarms', 'Trigger and log every emergency exit alarm on floors 2 through 6.', [3], 'IN_PROGRESS', 'HIGH', -4, 9, null, null],
            ['tsk-8', 'TSK-008', 1, 'Update the visitor logbook procedure', 'Fold the new badge rules into the front-desk visitor logbook steps.', [3], 'PENDING', 'NORMAL', -18, 2, null, null],
            ['tsk-9', 'TSK-009', 2, 'Reconcile the Q2 payroll registers', 'Match every register line against the posted payslips and flag the gaps.', [1], 'IN_PROGRESS', 'HIGH', -7, 25, null, null],
            ['tsk-10', 'TSK-010', 2, 'Verify the statutory deduction tables', 'Check SSS, PhilHealth and Pag-IBIG brackets against the current circulars.', [1], 'COMPLETED', 'NORMAL', 5, 26, 'All three tables match the latest circulars.', 'Verified, thanks.'],
            ['tsk-11', 'TSK-011', 2, 'Collect the signed payslip receipts', 'Chase the outstanding acknowledgement slips from the field teams.', [6], 'PENDING', 'LOW', -10, 6, null, null],
            ['tsk-12', 'TSK-012', 2, 'Summarise the audit findings', 'One-page summary of every variance found, with the proposed correction.', [0], 'PENDING', 'NORMAL', -14, 3, null, null],
        ];

        $taskIds = [];

        foreach ($specs as [$id, $code, $project, $title, $description, $empIdxs, $status, $priority, $due, $opened, $remarks, $reviewRemarks]) {
            $hasSubmission = in_array($status, ['SUBMITTED', 'COMPLETED', 'RETURNED'], true);
            $isComplete = $status === 'COMPLETED';
            $wasReviewed = in_array($status, ['COMPLETED', 'RETURNED', 'CANCELLED'], true);

            // Only employees the EmployeeSeeder actually produced: a missing
            // index simply leaves the task with one assignee fewer (or none).
            $assigneeIds = [];
            foreach ($empIdxs as $empIdx) {
                if (isset($employees[$empIdx])) {
                    $assigneeIds[] = $employees[$empIdx];
                }
            }

            // A submission lands a few days after the task was handed out, and
            // the review a day after that — never before the task existed.
            $submittedAgo = max($opened - 3, 0);
            $reviewedAgo = max($opened - 4, 0);

            $task = $this->upsert(ProjectTask::class, ['code' => $code], [
                'id' => $id,
                'project_id' => $projects[$project]['id'],
                'title' => $title,
                'description' => $description,
                'status' => $status,
                'priority' => $priority,
                'due_date' => $this->dateKey($due),
                'assigned_by' => $projects[$project]['manager'],
                'assigned_at' => $this->at($opened, 9, 0),
                'submitted_at' => $hasSubmission ? $this->at($submittedAgo, 16, 0) : null,
                'submitted_by' => $hasSubmission ? ($assigneeIds[0] ?? null) : null,
                'completed_at' => $isComplete ? $this->at($reviewedAgo, 17, 0) : null,
                'reviewed_by' => $wasReviewed ? $projects[$project]['manager'] : null,
                'remarks' => $remarks,
                'review_remarks' => $reviewRemarks,
                'created_at' => $this->at($opened, 9, 0),
            ]);

            // The pivot: diffed against what is already there, so a second run
            // neither duplicates nor re-stamps existing memberships.
            /** @var ProjectTask $task */
            $task->syncAssignees($assigneeIds, $projects[$project]['manager']);

            $taskIds[$code] = (string) $task->getKey();
        }

        $this->individualTask($projects[1], $employees);

        // The rework conversation on TSK-004.
        $comments = [
            ['tskc-1', $employeeAccountId, 'Paolo Bautista', 'ASSIGNEE', 'Readers swapped, badges re-enrolled.', 'SUBMITTED', [15, 16, 10]],
            ['tskc-2', $supervisorId, 'Andrea Lim', 'MANAGEMENT', 'The east reader still rejects contractor badges — please retest and resubmit.', 'RETURNED', [14, 9, 30]],
            ['tskc-3', $supervisorId, 'Andrea Lim', 'MANAGEMENT', 'Try re-enrolling the contractor batch against the new reader firmware first — the vendor pushed 2.4 last week.', null, [13, 8, 15]],
        ];

        if (isset($taskIds['TSK-004'])) {
            foreach ($comments as [$id, $authorId, $authorName, $side, $body, $event, $when]) {
                $this->upsert(TaskComment::class, ['id' => $id], [
                    'task_id' => $taskIds['TSK-004'],
                    'author_id' => $authorId,
                    'author_name' => $authorName,
                    'side' => $side,
                    'body' => $body,
                    'event' => $event,
                    'created_at' => $this->at($when[0], $when[1], $when[2]),
                ]);
            }
        }

        // Event notifications already waiting, so the bell is not empty on a
        // first run. dedupe_key stays null: these stand in for events that
        // fired once, not for reminders the sweep would try to re-derive.
        $notifications = [
            ['ntf-1', $employeeAccountId, 'TASK_RETURNED', 'TSK-004 returned for rework', 'The east reader still rejects contractor badges — please retest and resubmit.', '/my-tasks', null, [14, 9, 30]],
            ['ntf-2', $employeeAccountId, 'TASK_ASSIGNED', 'TSK-008 assigned to you', 'Update the visitor logbook procedure', '/my-tasks', null, [2, 9, 0]],
            ['ntf-4', $supervisorId, 'TASK_SUBMITTED', 'TSK-003 submitted for review', 'First draft attached — the visitor escort section still needs HR sign-off.', '/projects/'.$projects[1]['id'], null, [11, 16, 0]],
            ['ntf-5', $adminId, 'TASK_COMPLETED', 'TSK-010 accepted', 'Verify the statutory deduction tables — verified, thanks.', '/projects/'.$projects[2]['id'], [20, 9, 0], [22, 17, 0]],
        ];

        foreach ($notifications as [$id, $userId, $kind, $title, $body, $href, $readAt, $createdAt]) {
            if ($userId === null) {
                continue;
            }

            $this->upsert(Notification::class, ['id' => $id], [
                'user_id' => $userId,
                'kind' => $kind,
                'title' => $title,
                'body' => $body,
                'href' => $href,
                'dedupe_key' => null,
                'read_at' => $readAt === null ? null : $this->at($readAt[0], $readAt[1], $readAt[2]),
                'created_at' => $this->at($createdAt[0], $createdAt[1], $createdAt[2]),
            ]);
        }
    }

    /**
     * TSK-013, the INDIVIDUAL demo (class docblock).
     *
     * The task row holds no response: on an INDIVIDUAL task `submitted_by`,
     * `remarks` and the review columns stay empty, and each member's latest
     * response is their own ProjectTaskSubmission row. Those rows get no
     * literal id — BaseModel mints a ULID — because a response id is the
     * token a review decision posts, and a literal one would come back
     * unchanged if a walkthrough removed the member and a re-run re-seeded
     * them.
     *
     * @param  array{id: string, manager: int|null}  $project
     * @param  array<int, string>  $employees  0-based reference index => employee_profiles.id
     */
    private function individualTask(array $project, array $employees): void
    {
        // TSK-013 is also the NEXT code CreateTask mints (the highest + 1) on
        // any database whose seeded tasks stop at TSK-012, so a developer's
        // first task there already holds it. Matching on the code would take
        // that task over: move it, rewrite it, swap its crew and delete its
        // members' responses. Only the row this seeder created (resolved
        // through its stable identity) is updated; another TSK-013 skips it.
        $seededId = $this->seedId(ProjectTask::class, 'tsk-13');
        $codeHolder = ProjectTask::query()->where('code', 'TSK-013')->value('id');
        if ($codeHolder !== null && $codeHolder !== $seededId) {
            $this->command->warn('TSK-013 already belongs to another task: the INDIVIDUAL demo task was not seeded.');

            return;
        }

        // The demo emp-4 plus emp-5 and emp-6, never the reviewers' own emp-1
        // or emp-3. A missing index leaves the crew one short, as above.
        $crew = [];
        foreach ([3, 4, 5] as $empIdx) {
            if (isset($employees[$empIdx])) {
                $crew[$empIdx] = $employees[$empIdx];
            }
        }

        /** @var ProjectTask $task */
        $task = $this->upsert(ProjectTask::class, ['code' => 'TSK-013'], [
            'id' => 'tsk-13',
            'project_id' => $project['id'],
            'title' => 'Complete the CCTV operator refresher',
            'description' => "Each of you finishes the vendor's CCTV operator refresher and attaches your own completion certificate.",
            'status' => 'IN_PROGRESS',
            'priority' => 'NORMAL',
            'submission_mode' => 'INDIVIDUAL',
            'due_date' => $this->dateKey(-5),
            'assigned_by' => $project['manager'],
            'assigned_at' => $this->at(8, 9, 0),
            'submitted_at' => null,
            'submitted_by' => null,
            'completed_at' => null,
            'reviewed_by' => null,
            'remarks' => null,
            'review_remarks' => null,
            'created_at' => $this->at(8, 9, 0),
        ]);

        $task->syncAssignees(array_values($crew), $project['manager']);

        // Every run gives the demo emp-4 back "Not submitted", as the task row
        // above is reset: a walkthrough hands in and decides that response,
        // and a row left APPROVED on an IN_PROGRESS task would refuse the next
        // walkthrough's submission. syncAssignees() deletes by primary key to
        // avoid a gap lock under concurrent submissions; nothing runs beside a
        // seeder, so this delete can key on the (task, employee) pair.
        if (isset($crew[3])) {
            ProjectTaskSubmission::query()->where('task_id', (string) $task->getKey())->where('employee_id', '=', $crew[3])->delete();
        }

        // employee index, status, the member's note, submitted (days ago, h,
        // m), review remarks, reviewed (days ago, h, m). Each row is one
        // submission and one review old, hence revision 2: SubmitTask inserts
        // at 1 and every decision bumps it. The demo emp-4 is left out ON
        // PURPOSE — no row is what "Not submitted" is.
        $responses = [
            [4, 'APPROVED', 'Refresher finished, certificate attached.', [6, 15, 30], 'Certificate checks out, thanks.', [5, 10, 0]],
            [5, 'RETURNED', 'Done, certificate attached.', [3, 16, 0], "That certificate is for last year's module — please take the current one and resubmit.", [2, 11, 0]],
        ];

        foreach ($responses as [$empIdx, $status, $remarks, $submitted, $reviewRemarks, $reviewed]) {
            if (! isset($crew[$empIdx])) {
                continue;
            }

            // reviewed_by is an actor column, outside the model's $fillable:
            // upsert() force-fills it, like every seeded actor column.
            $this->upsert(ProjectTaskSubmission::class, ['task_id' => (string) $task->getKey(), 'employee_id' => $crew[$empIdx]], [
                'status' => $status,
                'remarks' => $remarks,
                'submitted_at' => $this->at($submitted[0], $submitted[1], $submitted[2]),
                'reviewed_by' => $project['manager'],
                'review_remarks' => $reviewRemarks,
                'reviewed_at' => $this->at($reviewed[0], $reviewed[1], $reviewed[2]),
                'revision' => 2,
                'created_at' => $this->at($submitted[0], $submitted[1], $submitted[2]),
            ]);
        }
    }
}
