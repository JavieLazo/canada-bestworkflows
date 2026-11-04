<?php

declare(strict_types=1);

namespace App\Domain\Reminders;

use App\Domain\Projects\TaskPolicy;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A literal port of copems/src/lib/reminders.ts.
 *
 * Event notifications are written by whatever action caused them. These are
 * the other half: nothing *happened*, a date simply arrived.
 *
 * Idempotency is the whole problem. Every reminder carries a `dedupeKey`
 * naming the RULE, the RECORD and the THING THAT TRIGGERED IT, and a reminder
 * is only written when no notification with that key already exists for that
 * account. Keying on the trigger rather than on today is what makes a task
 * that has been overdue for a fortnight announce itself once, not fourteen
 * times. The first half of that guarantee is the seen-key filter in the
 * platform's `SweepReminders`; the second is the (user_id, dedupe_key)
 * unique index.
 *
 * Reminders are generated for ONE account, from data that account can already
 * see, and only ever point at a route it holds. Hence the module gates below:
 * a reminder the reader cannot open is worse than no reminder.
 *
 * ⚠ WHAT IS DELIBERATELY NOT PORTED. In the reference, the sweep runs from a
 * React hook (src/hooks/useReminders.ts) which gates the whole run on
 * /api/tasks AND /api/projects having succeeded — both 403 for a role holding
 * neither module. docs/status-2026-08-20.md records the consequence: for an HR
 * Officer, the sweep never runs at all, so LEAVE_PENDING, DOCUMENT_EXPIRING,
 * DOCUMENT_EXPIRED and PROBATION_ENDING — the rules that role exists for —
 * silently never fire. That gating lives in the hook, not in reminders.ts, and
 * it is a bug rather than behaviour, so it is NOT reproduced here. The RULES
 * below are ported exactly; the caller passes whatever collections the account
 * may read and an empty list simply produces no reminders from that rule.
 *
 * DELIBERATE DIVERGENCE: the reference has one assignee per task and no
 * per-member responses. On an INDIVIDUAL task every assignee hands in their
 * own, so a member whose own response is submitted or approved is not
 * chased as an assignee, and TASK_RESPONSES_PENDING chases the assigner
 * about responses left waiting. Whether a member is done and which
 * responses wait are the owning module's rules, so they arrive precomputed
 * on each task row (`member_done`, `pending_responses`); this class never
 * reads that module.
 *
 * DELIBERATE DIVERGENCE: a document reminder opens the employee's record on
 * its Documents tab (`?tab=documents`), the only place the record lists its
 * documents; the reference links the bare record. A probation reminder keeps
 * the bare link, because the record opens on Profile, where Employee Details
 * shows the hiring and regularisation dates.
 *
 * Pure: no Eloquent, no DB, no config(). Calendar days are 'Y-m-d' STRINGS,
 * rows arrive as plain arrays, timestamps go through ManilaClock.
 */
final class ReminderSweep
{
    /** A task is announced this many days before it falls due. */
    public const TASK_DUE_SOON_DAYS = 2;

    /** A submission left unreviewed this long is chased. */
    public const REVIEW_STALE_DAYS = 2;

    /** A leave request left undecided this long is chased. */
    public const LEAVE_STALE_DAYS = 2;

    /** A document is announced this far ahead of expiry. */
    public const DOCUMENT_EXPIRY_DAYS = 30;

    /** Probation is announced this far ahead of the regularisation date. */
    public const PROBATION_NOTICE_DAYS = 14;

    /** Philippine practice: six months from hiring. */
    public const PROBATION_MONTHS = 6;

    /** A project is announced this far ahead of its due date. */
    public const PROJECT_DUE_SOON_DAYS = 7;

    /** How many days before a pay date an unfinished payroll is flagged (PAYROLL_DUE). */
    public const PAYROLL_NOTICE_DAYS = 7;

    /**
     * Most rows a single sweep may write.
     *
     * The reference reason was storage: every insert re-serialised the whole
     * local dataset to localStorage, and the bell only shows the 50 newest
     * rows. On MySQL that cost does not arise, but the cap stays because the
     * behaviour it produces is the same and is the point: a first run against
     * a long-neglected 201 file must not produce a document reminder per
     * expired scan in one burst. Nothing is lost by stopping short — whatever
     * is left over has no notification carrying its key yet, so the next sweep
     * picks it up.
     */
    public const MAX_REMINDERS_PER_SWEEP = 20;

    /**
     * TS: buildReminders(). Every reminder that has come due for ONE account.
     *
     * Rule order is load-bearing: the sweep keeps the first
     * MAX_REMINDERS_PER_SWEEP unseen reminders, walking the rule groups in
     * this same order, so the order the rules appear in decides what gets
     * announced first on a neglected dataset. It is the reference's order
     * exactly — assignee work, then handed-out work, then leave, then HR.
     *
     * Row shapes are the database columns, as arrays:
     *   $tasks     id, code, title, project_id, assignee_ids (list<string> of
     *              employee ids — the project_task_assignees pivot, [] when
     *              unassigned), submitted_by (employee id|null), assigned_by,
     *              status, due_date ('Y-m-d'), submitted_at (UTC instant);
     *              and two precomputed INDIVIDUAL-task facts, both optional:
     *              member_done (bool, default false — this account's own
     *              response is submitted or approved) and pending_responses
     *              (list of {employee_id, submitted_at (UTC instant)}, the
     *              responses awaiting review, default [])
     *   $projects  id, code, name, manager_id, status, due_date ('Y-m-d')
     *   $employees id, first_name, last_name, suffix, is_active, employment_status,
     *              date_hired ('Y-m-d'), date_regular ('Y-m-d'|null)
     *   $documents id, employee_id, name, expires_on ('Y-m-d')
     *   $leaves    id, code, employee_id, status, created_at (UTC instant)
     *
     * @param  string  $today  Manila calendar day, 'Y-m-d'.
     * @param  string  $userId  The signed-in ACCOUNT (user_accounts.id).
     * @param  list<string>  $modules  Module codes the account holds.
     * @param  string|null  $employeeId  Their employee record, when linked.
     * @param  list<array<string, mixed>>  $tasks
     * @param  list<array<string, mixed>>  $projects
     * @param  list<array<string, mixed>>  $employees
     * @param  list<array<string, mixed>>  $documents
     * @param  list<array<string, mixed>>  $leaves
     * @param  string|null  $group  One owner-authorized rule group for bounded callers; null runs all legacy rules.
     * @param  list<array{schedule_id: string, start: string, end: string, pay_date: string, label: string, deadline: string|null}>  $payrolls  the pay schedule periods not yet finalized, precomputed by Payroll
     * @return list<array{dedupeKey: string, kind: string, title: string, body: string|null, href: string}>
     */
    public static function build(
        string $today,
        string $userId,
        array $modules,
        ?string $employeeId,
        array $tasks = [],
        array $projects = [],
        array $employees = [],
        array $documents = [],
        array $leaves = [],
        ?string $group = null,
        array $payrolls = [],
    ): array {
        $out = [];

        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];
        foreach ($employees as $employee) {
            $byId[(string) $employee['id']] = $employee;
        }

        // TS: employeeName() — an id that resolves to nothing is "an employee",
        // never a blank or a raw id.
        $employeeName = static function (?string $id) use ($byId): string {
            $employee = ($id === null || ! isset($byId[$id])) ? null : $byId[$id];

            return EmployeeName::display($employee, 'an employee');
        };

        // Who submitted a task, for the assigner's review chase. The task is
        // SHARED by its assignees (ProjectTask docblock): whoever pressed
        // Submit is on submitted_by, so name them; a submission that predates
        // that column (or a legacy row) names every assignee instead, so the
        // sentence still points at the people who can answer for it.
        $submitterName = static function (array $task) use ($employeeName): string {
            $submittedBy = self::id($task['submitted_by'] ?? null);
            if ($submittedBy !== null && $submittedBy !== '') {
                return $employeeName($submittedBy);
            }

            $names = [];
            foreach (self::assigneeIds($task) as $assigneeId) {
                $names[] = $employeeName($assigneeId);
            }
            $names = array_values(array_unique($names));

            return $names === [] ? $employeeName(null) : implode(', ', $names);
        };

        /* --- as the person the work is assigned to --- */
        // Gated on TASKS as well as on holding an employee record: My Tasks is
        // the only page that shows an assignee their own work, and the
        // protected layout bounces a role without the module straight off it.
        //
        // A task with N assignees is announced to each of them: the dedupe
        // key names the task and the trigger, and the (user_id, dedupe_key)
        // unique index is per ACCOUNT, so co-assignees never collide. A task
        // with no assignees announces itself to nobody here.
        if (($group === null || $group === 'assigned') && $employeeId !== null && $employeeId !== '' && in_array('TASKS', $modules, true)) {
            foreach ($tasks as $task) {
                if (! in_array($employeeId, self::assigneeIds($task), true)) {
                    continue;
                }
                // A submitted task sits with the reviewer:
                // EMPLOYEE_TRANSITIONS leaves the assignee nothing to move it
                // to, so chasing them about it is a nag they cannot answer.
                // TASK_REVIEW_PENDING chases the assigner instead (below).
                // The same goes for an INDIVIDUAL member whose own response
                // is submitted (TASK_RESPONSES_PENDING chases that) or
                // approved: `member_done`.
                $due = self::dateKey($task['due_date'] ?? null);
                if (TaskPolicy::isClosed((string) $task['status'])
                    || TaskPolicy::isAwaitingReview((string) $task['status'])
                    || ($task['member_done'] ?? false) === true
                    || $due === null) {
                    continue;
                }

                $left = self::daysUntil($due, $today);

                if ($left < 0) {
                    $out[] = [
                        'dedupeKey' => 'TASK_OVERDUE:'.$task['id'].':'.$due,
                        'kind' => 'TASK_OVERDUE',
                        'title' => 'Task '.$task['code'].' is overdue',
                        'body' => $task['title'].' was due '.self::plural(abs($left), 'day').' ago.',
                        'href' => '/my-tasks',
                    ];
                } elseif ($left <= self::TASK_DUE_SOON_DAYS) {
                    $out[] = [
                        'dedupeKey' => 'TASK_DUE_SOON:'.$task['id'].':'.$due,
                        'kind' => 'TASK_DUE_SOON',
                        'title' => $left === 0
                            ? 'Task '.$task['code'].' is due today'
                            : 'Task '.$task['code'].' is due soon',
                        'body' => $task['title'].' — due '.self::inDays($left).'.',
                        'href' => '/my-tasks',
                    ];
                }
            }
        }

        /* --- as the person who handed the work out --- */
        // The review chases go to the ASSIGNER, who is usually the one to
        // decide. An assigner who is also on the crew cannot (ReviewTask and
        // ReviewTaskResponse refuse a self-review). The GROUP chase still
        // reaches them, deliberately: its rule ignores the refusal and its
        // key is frozen. The INDIVIDUAL chase skips them.
        if (in_array('PROJECTS', $modules, true)) {
            foreach (($group === null || $group === 'reviews') ? $tasks : [] as $task) {
                if (($task['assigned_by'] ?? null) !== $userId) {
                    continue;
                }

                // TASK_RESPONSES_PENDING, the DELIBERATE DIVERGENCE in the
                // class docblock: an INDIVIDUAL task with responses awaiting
                // review. It is keyed on the OLDEST of them, so a later
                // hand-in joins the reminder already sent and the key moves
                // on only once that response stops waiting. The trigger is
                // the TRUE instant to the millisecond (instantToken()), NOT
                // stamp()'s whole-second token: two responses handed in within
                // one second would share a key, and the second would never be
                // chased once the first was reviewed. THIS KEY FORMAT IS
                // FROZEN once it ships, like every other.
                $pending = self::pendingResponses($task);
                if ($pending !== []) {
                    if ($employeeId !== null && $employeeId !== ''
                        && in_array($employeeId, self::assigneeIds($task), true)) {
                        continue;
                    }
                    $oldest = $pending[0];
                    foreach ($pending as $response) {
                        if (self::instantOf($response['submitted_at'])->lt(self::instantOf($oldest['submitted_at']))) {
                            $oldest = $response;
                        }
                    }

                    $waiting = -self::daysUntil(self::dayKeyOf($oldest['submitted_at']), $today);
                    if ($waiting < self::REVIEW_STALE_DAYS) {
                        continue;
                    }

                    $ago = self::plural($waiting, 'day').' ago.';
                    $out[] = [
                        'dedupeKey' => 'TASK_RESPONSES_PENDING:'.$task['id'].':'.self::instantToken($oldest['submitted_at']),
                        'kind' => 'TASK_RESPONSES_PENDING',
                        'title' => 'Task '.$task['code'].' has responses waiting on your review',
                        'body' => count($pending) === 1
                            ? $employeeName($oldest['employee_id']).' submitted theirs '.$ago
                            : count($pending).' responses are waiting — the oldest from '.$employeeName($oldest['employee_id']).', '.$ago,
                        'href' => '/projects/'.$task['project_id'],
                    ];

                    continue;
                }

                $submittedAt = $task['submitted_at'] ?? null;
                if (! TaskPolicy::isAwaitingReview((string) $task['status']) || $submittedAt === null) {
                    continue;
                }

                $waiting = -self::daysUntil(self::dayKeyOf($submittedAt), $today);
                if ($waiting < self::REVIEW_STALE_DAYS) {
                    continue;
                }

                $out[] = [
                    // Keyed on the submission timestamp, not the day it landed:
                    // a task returned and resubmitted on the same day is a
                    // second thing to review, and a day-resolution key would
                    // swallow the chase for it.
                    'dedupeKey' => 'TASK_REVIEW_PENDING:'.$task['id'].':'.self::stamp($submittedAt),
                    'kind' => 'TASK_REVIEW_PENDING',
                    'title' => 'Task '.$task['code'].' is waiting on your review',
                    'body' => $submitterName($task).' submitted it '.self::plural($waiting, 'day').' ago.',
                    'href' => '/projects/'.$task['project_id'],
                ];
            }

            foreach (($group === null || $group === 'projects') ? $projects : [] as $project) {
                if (($project['manager_id'] ?? null) !== $userId) {
                    continue;
                }
                if ($project['status'] === 'COMPLETED' || $project['status'] === 'CANCELLED') {
                    continue;
                }
                $due = self::dateKey($project['due_date'] ?? null);
                if ($due === null) {
                    continue;
                }

                $left = self::daysUntil($due, $today);

                if ($left < 0) {
                    $out[] = [
                        'dedupeKey' => 'PROJECT_OVERDUE:'.$project['id'].':'.$due,
                        'kind' => 'PROJECT_OVERDUE',
                        'title' => 'Project '.$project['code'].' has passed its due date',
                        'body' => $project['name'].' was due '.self::plural(abs($left), 'day').' ago.',
                        'href' => '/projects/'.$project['id'],
                    ];
                } elseif ($left <= self::PROJECT_DUE_SOON_DAYS) {
                    $out[] = [
                        'dedupeKey' => 'PROJECT_DUE_SOON:'.$project['id'].':'.$due,
                        'kind' => 'PROJECT_DUE_SOON',
                        'title' => $left === 0
                            ? 'Project '.$project['code'].' is due today'
                            : 'Project '.$project['code'].' is due soon',
                        'body' => $project['name'].' — due '.self::inDays($left).'.',
                        'href' => '/projects/'.$project['id'],
                    ];
                }
            }
        }

        /* --- as the person who decides leave --- */
        if (($group === null || $group === 'leaves') && in_array('LEAVES', $modules, true)) {
            foreach ($leaves as $leave) {
                if ($leave['status'] !== 'PENDING') {
                    continue;
                }
                $waiting = -self::daysUntil(self::dayKeyOf($leave['created_at']), $today);
                if ($waiting < self::LEAVE_STALE_DAYS) {
                    continue;
                }

                // TS: `leave.code || "A leave request"` — a null OR EMPTY code
                // falls back, because JS treats "" as falsy.
                $code = $leave['code'] ?? null;

                $out[] = [
                    // No trigger component on purpose: a request stays pending
                    // until somebody acts, so one chase per request is the
                    // whole intent.
                    'dedupeKey' => 'LEAVE_PENDING:'.$leave['id'],
                    'kind' => 'LEAVE_PENDING',
                    'title' => (($code === null || $code === '') ? 'A leave request' : 'Request '.$code)
                        .' is still awaiting review',
                    'body' => 'Filed by '.$employeeName(self::id($leave['employee_id'] ?? null))
                        .' '.self::plural($waiting, 'day').' ago.',
                    'href' => '/leaves',
                ];
            }
        }

        /* --- as HR, over everybody's records --- */
        if (in_array('EMPLOYEES', $modules, true)) {
            foreach (($group === null || $group === 'documents') ? $documents : [] as $doc) {
                $expires = self::dateKey($doc['expires_on'] ?? null);
                if ($expires === null) {
                    continue;
                }
                // A resigned employee's lapsed licence is nobody's problem, and
                // their 201 file is the one place it would otherwise nag from
                // forever. TS reads `byId.get(id)?.is_active`, so a document
                // whose employee is not in the list is skipped too — undefined
                // is falsy. empty() reproduces that for null/0/false/'0'.
                $owner = $byId[(string) $doc['employee_id']] ?? null;
                if ($owner === null || empty($owner['is_active'])) {
                    continue;
                }

                $left = self::daysUntil($expires, $today);

                if ($left < 0) {
                    $out[] = [
                        'dedupeKey' => 'DOCUMENT_EXPIRED:'.$doc['id'].':'.$expires,
                        'kind' => 'DOCUMENT_EXPIRED',
                        'title' => $doc['name'].' has expired',
                        'body' => $employeeName(self::id($doc['employee_id']))
                            .' — expired '.self::plural(abs($left), 'day').' ago.',
                        'href' => '/employees/'.$doc['employee_id'].'?tab=documents',
                    ];
                } elseif ($left <= self::DOCUMENT_EXPIRY_DAYS) {
                    $out[] = [
                        'dedupeKey' => 'DOCUMENT_EXPIRING:'.$doc['id'].':'.$expires,
                        'kind' => 'DOCUMENT_EXPIRING',
                        'title' => $left === 0
                            ? $doc['name'].' expires today'
                            : $doc['name'].' expires soon',
                        'body' => $employeeName(self::id($doc['employee_id']))
                            .' — expires '.self::inDays($left).'.',
                        'href' => '/employees/'.$doc['employee_id'].'?tab=documents',
                    ];
                }
            }

            foreach (($group === null || $group === 'probation') ? $employees : [] as $employee) {
                if (empty($employee['is_active'])) {
                    continue;
                }
                $due = self::regularisationDate($employee);
                if ($due === null) {
                    continue;
                }
                $left = self::daysUntil($due, $today);
                if ($left < 0 || $left > self::PROBATION_NOTICE_DAYS) {
                    continue;
                }

                $name = EmployeeName::display($employee, 'an employee');

                $out[] = [
                    'dedupeKey' => 'PROBATION_ENDING:'.$employee['id'].':'.$due,
                    'kind' => 'PROBATION_ENDING',
                    'title' => $left === 0
                        ? $name.' finishes probation today'
                        : $name.' finishes probation soon',
                    'body' => 'Six months from hiring falls '.self::inDays($left).'.',
                    'href' => '/employees/'.$employee['id'],
                ];
            }
        }

        // PAYROLL_DUE: a pay schedule's period whose pay date, or a deadline
        // before it, is within PAYROLL_NOTICE_DAYS and whose payroll is not
        // finalized. Payroll's ReminderData hands in only the periods an
        // all-data PAYROLL holder may be told about, each with the schedule
        // id (segment 2, authorized by that group) and the nearest date
        // still ahead as the trigger; one reminder per period and date.
        foreach (($group === null || $group === 'payroll') ? $payrolls : [] as $period) {
            $payDate = (string) $period['pay_date'];
            $left = self::daysUntil($payDate, $today);
            if ($left < 0 || $left > self::PAYROLL_NOTICE_DAYS) {
                continue;
            }
            $out[] = [
                'dedupeKey' => 'PAYROLL_DUE:'.$period['schedule_id'].':'.$period['start'].':'.$payDate,
                'kind' => 'PAYROLL_DUE',
                'title' => $left === 0
                    ? 'Payroll for '.$period['label'].' is paid today'
                    : 'Payroll for '.$period['label'].' is paid '.self::inDays($left),
                'body' => ($period['deadline'] ?? null) === null
                    ? 'No finalized payroll covers it yet.'
                    : $period['deadline'].' — no finalized payroll covers it yet.',
                'href' => '/payroll',
            ];
        }

        return $out;
    }

    /**
     * TS: daysUntil() — whole days from `today` to `date`; negative once the
     * date has passed.
     *
     * The reference builds both ends with `new Date(`${key}T00:00:00`)`, i.e.
     * LOCAL midnight, and local is Manila for every user of this system. So
     * both ends go through ManilaClock rather than being treated as UTC.
     * (The reference's DST rationale for the Math.round does not apply in
     * Manila, which has had no DST since 1978, but the rounding is ported
     * anyway — it is what makes the function total.)
     *
     * NOT the same function as ProjectPolicy::daysUntilDate(), which does the
     * same job from UTC components. They agree on every well-formed key; the
     * reference keeps both and so does this port.
     */
    public static function daysUntil(string $date, string $today): int
    {
        return self::jsRound((self::atMidnight($date) - self::atMidnight($today)) / 86_400_000);
    }

    /**
     * TS: addMonths() — adds whole months to a date key, clamping to the end
     * of the target month.
     *
     * Doing this with a date object is wrong at month ends: 31 August + 6
     * months asks for 31 February, which JS silently rolls into 2 or 3 March
     * (and PHP's modify('+6 months') into 3 March) and would put a probation
     * review days late. Working on the key keeps it off the date object
     * entirely, which also keeps it out of reach of the timezone.
     */
    public static function addMonths(string $key, int $months): ?string
    {
        $parts = explode('-', substr($key, 0, 10));
        // `explode()` always returns at least one element, so the year needs no
        // default: an empty key casts to 0, which the guard below rejects anyway.
        $y = (int) $parts[0];
        $m = isset($parts[1]) ? (int) $parts[1] : 0;
        $d = isset($parts[2]) ? (int) $parts[2] : 0;

        // TS: `if (!y || !m || !d) return null` — a zero in any component,
        // including year 0, is rejected rather than computed with.
        if ($y === 0 || $m === 0 || $d === 0) {
            return null;
        }

        $total = $y * 12 + ($m - 1) + $months;
        $year = (int) floor($total / 12);   // floor, not intdiv: JS Math.floor
        $month = $total % 12;               // 0-based, as in the reference

        // Day 0 of the following month is the last day of this one — the same
        // trick as `new Date(year, month + 1, 0).getDate()`, one month index
        // further along because PHP's mktime months are 1-based.
        $lastDay = (int) date('j', (int) mktime(0, 0, 0, $month + 2, 0, $year));

        // The year is NOT zero-padded in the reference either; a 4-digit year
        // makes that invisible.
        return sprintf('%d-%02d-%02d', $year, $month + 1, min($d, $lastDay));
    }

    /**
     * TS: regularisationDate() — the date an employee is due to regularise, or
     * null when it does not apply.
     *
     * @param  array<string, mixed>  $employee
     */
    public static function regularisationDate(array $employee): ?string
    {
        // Records Management can rename or add statuses, so this asks whether
        // the status *is* probation rather than comparing against one stored
        // spelling.
        if (! self::isProbationary(self::id($employee['employment_status'] ?? null))) {
            return null;
        }

        $regular = self::dateKey($employee['date_regular'] ?? null);
        if ($regular !== null) {
            return null;
        }

        $hired = self::dateKey($employee['date_hired'] ?? null);
        if ($hired === null) {
            return null;
        }

        return self::addMonths($hired, self::PROBATION_MONTHS);
    }

    /**
     * hris.ts: statusKey() + isProbationary(). "Job Order", "JOB_ORDER" and
     * "job order" are one status.
     *
     * Duplicated here rather than imported because the HRIS employment-status
     * helpers are another agent's file; when App\Domain\Hris lands with
     * statusKey()/isProbationary(), delete this pair and call that. The
     * behaviour it carries is load-bearing: renaming "Probationary" in Records
     * Management silently disables the regularisation reminder.
     */
    private static function isProbationary(?string $value): bool
    {
        return self::statusKey($value) === 'PROBATIONARY';
    }

    /** hris.ts: statusKey() */
    private static function statusKey(?string $value): string
    {
        $key = mb_strtoupper(trim($value ?? ''));
        $key = (string) preg_replace('/[^A-Z0-9]+/', '_', $key);

        return (string) preg_replace('/^_|_$/', '', $key);
    }

    /** TS: plural() — "1 day", "5 days". */
    private static function plural(int $n, string $one): string
    {
        return $n.' '.$one.($n === 1 ? '' : 's');
    }

    /** TS: inDays() — "today" / "tomorrow" / "in 5 days". Never "in 0 days". */
    private static function inDays(int $n): string
    {
        return match ($n) {
            0 => 'today',
            1 => 'tomorrow',
            default => 'in '.self::plural($n, 'day'),
        };
    }

    /** TS: atMidnight() — local (Manila) midnight of a date key, in ms. */
    private static function atMidnight(string $key): int
    {
        return ManilaClock::ms(ManilaClock::instant($key.'T00:00:00'));
    }

    /**
     * Which Manila calendar day an instant belongs to.
     *
     * ⚠ A raw DATETIME(3) string read back from MySQL is UTC and carries no
     * offset, and ManilaClock::instant() reads an offset-less string as MANILA
     * WALL CLOCK. Hand this a CarbonImmutable (which is what Eloquent gives
     * you) or a string that carries 'Z' or an offset. Passing a bare
     * '2026-08-19 02:33:44' from a raw query is wrong by eight hours.
     */
    private static function dayKeyOf(DateTimeInterface|string $at): string
    {
        return ManilaClock::dayKey(self::instantOf($at));
    }

    /**
     * The stable trigger component of a dedupe key: one submission, one key.
     *
     * THE FORMAT IS FROZEN, quirk included. The key is an opaque token matched
     * against rows already written, so the value minted for a given submission
     * must never change. The stamp is minted from submitted_at's UTC digits
     * as an offset-less 'Y-m-d H:i:s' string, which ManilaClock::instant()
     * reads as Manila wall clock — so every TASK_REVIEW_PENDING row in the
     * table carries a stamp eight hours BEFORE the true instant, at whole
     * seconds. An instant arriving here is reduced to those digits and sent
     * down that path, byte for byte; a string is read as-is with a zone, as
     * Manila without. Minting from the true instant instead would re-chase
     * every submission already chased once.
     * The day arithmetic ({@see self::dayKeyOf()}) is separate and reads the
     * instant itself, so it is NOT eight hours out.
     *
     * TASK_REVIEW_PENDING ONLY. Never mint a new kind's trigger here: the
     * quirk exists solely because rows already carry it.
     */
    private static function stamp(DateTimeInterface|string $at): string
    {
        $digits = $at instanceof DateTimeInterface
            ? CarbonImmutable::instance($at)->utc()->format('Y-m-d H:i:s')
            : $at;

        return ManilaClock::instant($digits)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * The trigger of a TASK_RESPONSES_PENDING key: the true UTC instant, to
     * the millisecond, as 'Y-m-d\TH:i:s.v\Z'. Read the same way as the day
     * arithmetic ({@see self::instantOf()}), so there is no eight-hour quirk.
     * Keeping the milliseconds matters: SubmitTask stamps each response
     * under the task's lock, one after another, so responses handed in within
     * the same second still get distinct keys. The format is frozen once the
     * kind ships.
     */
    private static function instantToken(DateTimeInterface|string $at): string
    {
        return self::instantOf($at)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function instantOf(DateTimeInterface|string $at): CarbonImmutable
    {
        return $at instanceof DateTimeInterface
            ? CarbonImmutable::instance($at)
            : ManilaClock::instant($at);
    }

    /**
     * A DATE column as the 'Y-m-d' string the rules compare on.
     *
     * A DATE has no time and no zone, so a date object arriving here is
     * formatted as-is — never converted, which would shift it a day. An empty
     * string reads as absent, matching the reference's `if (!due_date)`.
     */
    private static function dateKey(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return substr($value, 0, 10);
    }

    /** A nullable id/enum column as a string, without coercing null to ''. */
    private static function id(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * A task row's `assignee_ids` as a clean list of strings. Anything that is
     * not a list (absent, null, a stray scalar) reads as unassigned — the
     * sweep must never guess an owner for a row it cannot read.
     *
     * @param  array<string, mixed>  $task
     * @return list<string>
     */
    private static function assigneeIds(array $task): array
    {
        $raw = $task['assignee_ids'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            } elseif (is_int($id)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * A task row's `pending_responses` as a clean list: the INDIVIDUAL
     * responses awaiting review, as the owning module computed them. Anything
     * that is not a list, and any entry without a submission instant, is left
     * out — the sweep must never guess when a response was handed in.
     *
     * @param  array<string, mixed>  $task
     * @return list<array{employee_id: string|null, submitted_at: DateTimeInterface|string}>
     */
    private static function pendingResponses(array $task): array
    {
        $raw = $task['pending_responses'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $responses = [];
        foreach ($raw as $response) {
            if (! is_array($response)) {
                continue;
            }
            $submittedAt = $response['submitted_at'] ?? null;
            if ($submittedAt instanceof DateTimeInterface || (is_string($submittedAt) && $submittedAt !== '')) {
                $responses[] = ['employee_id' => self::id($response['employee_id'] ?? null), 'submitted_at' => $submittedAt];
            }
        }

        return $responses;
    }

    /**
     * JS Math.round, to an integer.
     *
     * NOT PHP's round(): that is half-away-from-zero, while JS is half-UP
     * (toward +Infinity), so Math.round(-0.5) is -0 and round(-0.5) is -1.
     * These differences go negative on every overdue record. Money::round() is
     * the 2-dp money boundary and is the wrong tool for a day count; this is
     * the same floor-based construction without the *100 / 100.
     */
    private static function jsRound(float $value): int
    {
        $floor = floor($value);

        return (int) (($value - $floor) >= 0.5 ? $floor + 1 : $floor);
    }
}
