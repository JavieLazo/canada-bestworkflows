<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure;

use App\Models\User;
use App\Platform\Notifications\NotificationRecipients;
use App\Platform\Notifications\ReminderRecipient;
use App\Support\Access;

/** Resolves account scopes without exposing account persistence to notification callers. */
final class NotificationRecipientQuery implements NotificationRecipients
{
    /** @param list<string> $employeeIds
     * @param list<int> $except
     * @return list<int> */
    public function employees(array $employeeIds, array $except): array
    {
        return array_values(User::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->reject(static fn (int $id): bool => in_array($id, $except, true))
            ->values()->all());

    }

    /** @return list<int> */
    public function moduleHolders(string $module, int|string|null $exceptUserId): array
    {
        return array_values(User::query()
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->join('role_module_grants', 'role_module_grants.role_id', '=', 'roles.id')
            ->where('users.is_active', true)
            ->where('roles.all_data', true)
            ->where('role_module_grants.module', $module)
            ->when(
                $exceptUserId !== null && $exceptUserId !== '',
                static fn ($q) => $q->where('users.id', '!=', (int) $exceptUserId),
            )
            ->distinct()
            ->pluck('users.id')->map(static fn ($id): int => (int) $id)->values()->all());

    }

    /** @return iterable<ReminderRecipient> */
    public function active(): iterable
    {
        foreach (User::query()->where('is_active', true)->with('role.grants')->lazyById(100) as $user) {
            yield new ReminderRecipient(Access::forUser($user));
        }
    }
}
