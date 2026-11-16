<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Support\Access;

/** System-only recipient resolution used after an authorized operation or by the scheduler. */
interface NotificationRecipients
{
    /** @param list<string> $employeeIds
     * @param list<int> $except
     * @return list<int> */
    public function employees(array $employeeIds, array $except): array;

    /** @return list<int> */
    public function moduleHolders(string $module, int|string|null $exceptUserId): array;

    /** @param list<int> $ids
     * @return iterable<Access> Current active scopes for an authorized delivery, never an account directory.
     */
    public function accounts(array $ids): iterable;

    /** @return iterable<ReminderRecipient> */
    public function active(): iterable;
}
