<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Models\Notification;
use App\Models\User;
use App\Support\Realtime;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * In-app notifications, written by the operation that causes them.
 *
 * The port of `src/lib/api/notify.ts` (the server-side fan-out — the demo's
 * client-side `src/lib/notifications.ts` is deliberately inert in production
 * because "an inbox anyone can address is not an inbox") plus the reminder
 * write path of `src/lib/data/notifications.ts` (`createReminderNotifications`).
 *
 * TWO INVARIANTS, straight from the reference:
 *
 *  1. **Fire-and-forget.** A notification that does not land must never break
 *     the action that triggered it — "the task really was assigned, and
 *     failing the request because the bell did not ring would be a worse lie
 *     than a missing notification" (notify.ts:9-12). Every method swallows and
 *     logs; none throws.
 *  2. **The addressee is server-derived.** `user_id` is resolved here — from
 *     an employee link, a module grant, or the session — never accepted off a
 *     request. It is set with `setAttribute`, not `fill()`: the column is
 *     deliberately not mass-assignable on {@see Notification}.
 *
 * TRANSACTIONS: writes go through the default connection, so a caller already
 * inside `DB::transaction()` has the notification join its transaction — a
 * rolled-back assignment takes its bell ring with it. There is no internal
 * transaction here: one row per recipient, and a partial fan-out is exactly
 * what the reference's `Promise.all` of independent creates produced.
 *
 * DEDUPE (`dedupe_key`) — reference semantics, do not "improve":
 *  - EVENT notifications ({@see account}, {@see employee}, {@see employees},
 *    {@see moduleHolders}) always write `dedupe_key = NULL`. They fire from a
 *    write that only happens once, and the `(user_id, dedupe_key)` unique
 *    index does not bind on NULL, so they repeat freely.
 *  - REMINDERS ({@see reminders}) are derived by the scheduled sweep, not
 *    by opening a browser; each row carries a key naming the RULE, the
 *    RECORD and the TRIGGER (`ReminderSweep::build()`), and `insertOrIgnore`
 *    against the unique index is what stops the same reminder landing twice.
 *
 * `kind` starts from the 19 strings in `src/lib/notifications.ts`
 * (NOTIFICATION_KINDS): TASK_ASSIGNED, TASK_REASSIGNED, TASK_SUBMITTED,
 * TASK_RETURNED, TASK_COMPLETED, TASK_CANCELLED, TASK_COMMENT, LEAVE_FILED,
 * LEAVE_REVIEWED, PAYSLIP_POSTED, and the nine reminder kinds. ems adds its
 * own on top — among them LEAVE_WITHDRAWN; for a member's response on an
 * INDIVIDUAL task, TASK_RESPONSE_SUBMITTED, TASK_RESPONSE_APPROVED and
 * TASK_RESPONSE_RETURNED; for a task status set by hand, TASK_PENDING
 * and TASK_IN_PROGRESS; and, to Payroll when a leave is approved onto days
 * a posted payslip covers, LEAVE_APPROVED_ON_PAID_DAYS. Kept a plain string on purpose — a kind added later
 * must still render, not throw.
 */
final class Notifier
{
    /**
     * Tells ONE account. Does nothing when there is nobody to tell.
     *
     * Port of `notifyAccount` (notify.ts:23-38).
     *
     * @param  int|string|null  $userId  The `users` row to tell (Access::userId
     *                                   arrives as a string; both are accepted).
     * @param  string  $kind  A notification kind: NOTIFICATION_KINDS or one
     *                        ems adds (class docblock).
     * @param  string|null  $href  Where clicking it should go, app-relative
     *                             (e.g. '/my-tasks').
     */
    public static function account(
        int|string|null $userId,
        string $kind,
        string $title,
        ?string $body = null,
        ?string $href = null,
    ): void {
        if ($userId === null || $userId === '') {
            return;
        }

        if (self::write($userId, $kind, $title, $body, $href)) {
            self::ring([$userId]);
        }
    }

    /**
     * The single write. Returns whether the row landed, so a fan-out can knock
     * once for the accounts it actually reached instead of once per account.
     *
     * Swallows and logs, per INVARIANT 1 — every caller depends on that, and
     * the boolean is for batching the announcement, never for failing a
     * request.
     */
    private static function write(
        int|string $userId,
        string $kind,
        string $title,
        ?string $body,
        ?string $href,
    ): bool {
        try {
            $notification = new Notification;
            $notification->fill([
                'kind' => mb_substr($kind, 0, 60),
                'title' => mb_substr($title, 0, 300),
                'body' => $body === null ? null : mb_substr($body, 0, 2000),
                'href' => $href === null ? null : mb_substr($href, 0, 500),
                'dedupe_key' => null,
            ]);
            // The addressee is server-assigned, never mass-assigned.
            $notification->setAttribute('user_id', (int) $userId);
            $notification->save();

            return true;
        } catch (Throwable $e) {
            Log::error('[notify] write failed', ['kind' => $kind, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Knock on the bells of the accounts just written to — one broadcast for
     * the whole fan-out, not one per recipient, because a leave filed for five
     * reviewers should not be five outbound HTTPS calls.
     *
     * Fire-and-forget in the same sense the write is: {@see Realtime::announce}
     * waits for the caller's transaction to commit, runs after the response is
     * flushed, and swallows its own failures. A bell that does not ring in
     * real time still rings at the next 60-second tick.
     *
     * @param  list<int|string>  $userIds
     */
    private static function ring(array $userIds): void
    {
        Realtime::announce(array_map(
            static fn (int|string $id): string => Realtime::account($id),
            $userIds,
        ));
    }

    /**
     * Tells whoever holds the login attached to an employee.
     *
     * Port of `notifyEmployee` (notify.ts:40-57): "Tasks are assigned to an
     * `employee_profiles` row but a notification is addressed to a
     * `user_accounts` one, so every notification about work crosses here. An
     * employee with no login is simply not told." Every ACTIVE account linked
     * to the employee is told, exactly as the reference's `findMany`.
     */
    public static function employee(
        ?string $employeeId,
        string $kind,
        string $title,
        ?string $body = null,
        ?string $href = null,
    ): void {
        self::employees([$employeeId], [], $kind, $title, $body, $href);
    }

    /**
     * Tells every ACTIVE login of every listed employee, once each, except the
     * accounts in `$exceptUserIds` — the multi-assignee fan-out with the
     * told-ledger that Tasks and Projects need.
     *
     * The port of notify.ts:40-57 run over a crew. The except-list is there
     * because one person can qualify for a task event twice — as the assigner
     * (`assigned_by` holds a users id) and as an assignee (an employee id) —
     * and the actor must never be told about their own action. Callers pass
     * the actor's account and the assigner's account here, then tell the
     * assigner separately through {@see account()} when they are not the
     * actor.
     *
     * One lookup for the whole crew, `orderBy('id')` so the rows land in a
     * stable order, each written in its own try (a failed row is logged and
     * skipped, the rest still land), and ONE bell knock for the fan-out.
     *
     * @param  iterable<?string>  $employeeIds  null and '' entries are ignored
     * @param  iterable<int|string|null>  $exceptUserIds  users ids never told, whatever they are linked to
     * @param  list<int>|null  $allowedUserIds
     */
    public static function employees(
        iterable $employeeIds,
        iterable $exceptUserIds,
        string $kind,
        string $title,
        ?string $body = null,
        ?string $href = null,
        ?array $allowedUserIds = null,
    ): void {
        $ids = [];
        foreach ($employeeIds as $employeeId) {
            if ($employeeId !== null && $employeeId !== '' && ! in_array($employeeId, $ids, true)) {
                $ids[] = $employeeId;
            }
        }

        if ($ids === []) {
            return;
        }

        $told = [];
        foreach ($exceptUserIds as $userId) {
            if ($userId !== null && $userId !== '') {
                $told[] = (int) $userId;
            }
        }

        try {
            $accountIds = app(NotificationRecipients::class)->employees($ids, $told);
            if ($allowedUserIds !== null) {
                $accountIds = array_values(array_intersect($accountIds, $allowedUserIds));
            }
        } catch (Throwable $e) {
            Log::error('[notify] lookup failed', ['error' => $e->getMessage()]);

            return;
        }

        self::ring(self::writeEach($accountIds, $kind, $title, $body, $href));
    }

    /**
     * Tells everyone whose role grants a module — how a filed leave request
     * reaches the people who review them.
     *
     * Port of `notifyModuleHolders` (notify.ts:59-83): active accounts whose
     * role has BOTH `all_data` and a grant for the module. "The actor is
     * excluded: work you did yourself is not news to you."
     *
     * Explicit joins rather than relations on purpose — this must keep working
     * whatever relations the User model does or does not declare.
     *
     * @param  string  $module  A ModuleCode value, e.g. 'LEAVES'.
     * @param  int|string|null  $exceptUserId  The actor, excluded from the fan-out.
     */
    public static function moduleHolders(
        string $module,
        int|string|null $exceptUserId,
        string $kind,
        string $title,
        ?string $body = null,
        ?string $href = null,
    ): void {
        try {
            $accountIds = app(NotificationRecipients::class)->moduleHolders($module, $exceptUserId);
        } catch (Throwable $e) {
            Log::error('[notify] fan-out failed', ['error' => $e->getMessage()]);

            return;
        }

        self::ring(self::writeEach($accountIds, $kind, $title, $body, $href));
    }

    /**
     * One row per recipient — the port of the reference's `Promise.all` of
     * independent creates, including its partial-fan-out behaviour: an account
     * whose row fails is logged and skipped, and the rest still land.
     *
     * @param  iterable<int|string>  $userIds
     * @return list<int|string> those actually written
     */
    private static function writeEach(
        iterable $userIds,
        string $kind,
        string $title,
        ?string $body,
        ?string $href,
    ): array {
        $written = [];

        foreach ($userIds as $userId) {
            if (self::write($userId, $kind, $title, $body, $href)) {
                $written[] = $userId;
            }
        }

        return $written;
    }

    /**
     * Writes scheduler-resolved recipients and returns actual persistence outcomes.
     *
     * Port of `createReminderNotifications` (data/notifications.ts:55-80):
     * "These are self-addressed … so the handler takes the recipient from the
     * session and `user_id` is never sent." Here the scheduler resolves each
     * account from Identity Access; the request cannot choose a recipient.
     *
     * One `INSERT … IGNORE` against the `(user_id, dedupe_key)` unique index:
     * a key already recorded for this account is skipped WITHOUT disturbing
     * the rest of the batch — the database half of the sweep's idempotency
     * guarantee (the first half is the sweep pre-filtering on the keys it has
     * already seen). Reject oversized keys before INSERT IGNORE can truncate
     * them, and distinguish duplicates from ignored constraint failures.
     *
     * @param  list<array{dedupeKey: string, kind: string, title: string, body: string|null, href: string|null}>  $rows
     * @return array{inserted: int, duplicates: int, failed: int, errors: int}
     */
    public static function reminders(int|string|null $userId, array $rows): array
    {
        if ($userId === null || $userId === '' || $rows === []) {
            return ['inserted' => 0, 'duplicates' => 0, 'failed' => count($rows), 'errors' => $rows === [] ? 0 : 1];
        }
        $inserted = 0;
        try {
            $now = now()->format('Y-m-d H:i:s.v');

            $values = [];
            foreach ($rows as $row) {
                if ($row['dedupeKey'] === '' || strlen($row['dedupeKey']) > 191) {
                    throw new RuntimeException('Invalid reminder deduplication key length.');
                }
                $values[] = [
                    'id' => (string) Str::ulid(),
                    'user_id' => (int) $userId,
                    'kind' => mb_substr($row['kind'], 0, 60),
                    'title' => mb_substr($row['title'], 0, 300),
                    'body' => $row['body'] === null ? null : mb_substr($row['body'], 0, 2000),
                    'href' => $row['href'] === null ? null : mb_substr($row['href'], 0, 500),
                    'dedupe_key' => $row['dedupeKey'],
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => null,
                ];
            }

            // The count is what insertOrIgnore actually inserted: a sweep
            // whose every key was already recorded knocks on nobody's bell.
            $inserted = Notification::query()->insertOrIgnore($values);
            if ($inserted > 0) {
                self::ring([$userId]);
            }
            $keys = array_values(array_unique(array_column($rows, 'dedupeKey')));
            $stored = Notification::query()->where('user_id', (int) $userId)->whereIn('dedupe_key', $keys)->pluck('dedupe_key')->all();
            $failed = count(array_diff($keys, $stored));

            return ['inserted' => $inserted, 'duplicates' => count($rows) - $inserted - $failed, 'failed' => $failed, 'errors' => $failed > 0 ? 1 : 0];
        } catch (Throwable $e) {
            Log::error('[notify] reminder write failed', ['error' => $e->getMessage()]);

            return ['inserted' => $inserted, 'duplicates' => 0, 'failed' => count($rows) - $inserted, 'errors' => 1];
        }
    }
}
