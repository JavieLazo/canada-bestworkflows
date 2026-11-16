<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Notification;
use App\Models\User;

/** Compatibility adapter; implementation belongs to \App\Platform\Notifications\Notifier. */
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
     *                        ems added (the platform Notifier's docblock).
     * @param  string|null  $href  Where clicking it should go, app-relative
     *                             (e.g. '/my-tasks').
     */
    public static function account(int|string|null $userId, string $kind, string $title, ?string $body = null, ?string $href = null): void
    {
        \App\Platform\Notifications\Notifier::account($userId, $kind, $title, $body, $href);
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
    public static function employee(?string $employeeId, string $kind, string $title, ?string $body = null, ?string $href = null): void
    {
        \App\Platform\Notifications\Notifier::employee($employeeId, $kind, $title, $body, $href);
    }

    /**
     * Tells every ACTIVE login of every listed employee, once each, except the
     * accounts in `$exceptUserIds` — the multi-assignee fan-out with the
     * told-ledger that Tasks and Projects need.
     *
     * The port of notify.ts:40-57 run over a crew. The except-list exists
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
    public static function employees(iterable $employeeIds, iterable $exceptUserIds, string $kind, string $title, ?string $body = null, ?string $href = null, ?array $allowedUserIds = null): void
    {
        \App\Platform\Notifications\Notifier::employees($employeeIds, $exceptUserIds, $kind, $title, $body, $href, $allowedUserIds);
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
    public static function moduleHolders(string $module, int|string|null $exceptUserId, string $kind, string $title, ?string $body = null, ?string $href = null): void
    {
        \App\Platform\Notifications\Notifier::moduleHolders($module, $exceptUserId, $kind, $title, $body, $href);
    }

    /**
     * Writes the reminder sweep's own notifications — always addressed to the
     * person running it, and deduplicated on `dedupe_key`.
     *
     * Port of `createReminderNotifications` (data/notifications.ts:55-80):
     * "These are self-addressed … so the handler takes the recipient from the
     * session and `user_id` is never sent." The caller passes
     * `Access::userId`; `$rows` is `ReminderSweep::build()` output verbatim.
     *
     * One `INSERT … IGNORE` against the `(user_id, dedupe_key)` unique index:
     * a key already recorded for this account is skipped WITHOUT disturbing
     * the rest of the batch — the database half of the sweep's idempotency
     * guarantee (the first half is the sweep pre-filtering on the keys it has
     * already seen). `dedupe_key` is NOT clamped: the column is sized so an
     * over-long generated key raises a visible error instead of silently
     * truncating into a false duplicate.
     *
     * @param  list<array{dedupeKey: string, kind: string, title: string, body: string|null, href: string|null}>  $rows
     */
    public static function reminders(int|string|null $userId, array $rows): void
    {
        \App\Platform\Notifications\Notifier::reminders($userId, $rows);
    }
}
