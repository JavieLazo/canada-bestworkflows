<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Domain\Tasks;

final class TaskInbox
{
    public const ACTIONABLE = ['PENDING', 'IN_PROGRESS', 'RETURNED'];

    public const WAITING = ['SUBMITTED', 'APPROVED'];

    public const CLOSED = ['COMPLETED', 'CANCELLED'];

    public const QUEUES = ['ALL' => 'All tasks', 'ACTIONABLE' => 'To do', 'RETURNED' => 'Returned', 'OVERDUE' => 'Overdue', 'WAITING' => 'Awaiting review', 'CLOSED' => 'Closed'];

    public const BUCKETS = ['OVERDUE' => 'Overdue', 'IN_PROGRESS' => 'In progress', 'RETURNED' => 'Returned', 'TODAY' => 'Today', 'NEXT' => 'Next up', 'UPCOMING' => 'Upcoming', 'WAITING' => 'Awaiting review', 'CLOSED' => 'Closed'];

    public const RANKS = ['OVERDUE' => 0, 'IN_PROGRESS' => 1, 'RETURNED' => 2, 'TODAY' => 3, 'NEXT' => 4, 'UPCOMING' => 5, 'WAITING' => 6, 'CLOSED' => 7];

    /** @return list<string> */
    public static function statuses(string $queue): array
    {
        return match ($queue) {
            'ACTIONABLE', 'OVERDUE' => self::ACTIONABLE,
            'RETURNED' => ['RETURNED'],
            'WAITING' => self::WAITING,
            'CLOSED' => self::CLOSED,
            default => [],
        };
    }

    public static function overdue(string $status, ?string $due, string $today): bool
    {
        return in_array($status, self::ACTIONABLE, true) && $due !== null && $due < $today;
    }

    public static function bucket(string $status, ?string $start, ?string $due, string $today): string
    {
        return match (true) {
            self::overdue($status, $due, $today) => 'OVERDUE',
            $status === 'IN_PROGRESS' => 'IN_PROGRESS',
            $status === 'RETURNED' => 'RETURNED',
            in_array($status, self::WAITING, true) => 'WAITING',
            in_array($status, self::CLOSED, true) => 'CLOSED',
            in_array($status, self::ACTIONABLE, true) && ($due === $today || $start === $today) => 'TODAY',
            in_array($status, self::ACTIONABLE, true) && $start !== null && $start > $today => 'UPCOMING',
            default => 'NEXT',
        };
    }
}
