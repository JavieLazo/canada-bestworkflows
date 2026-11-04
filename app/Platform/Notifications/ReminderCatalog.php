<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Models\Notification;
use App\Support\Access;
use Illuminate\Support\Collection;
use LogicException;

/** Shared rule ownership for bounded generation and read-time authorization of old reminders. */
final class ReminderCatalog
{
    public const array GROUPS = ['assigned', 'reviews', 'projects', 'leaves', 'documents', 'probation', 'payroll'];

    /**
     * Every kind a reminder source mints, and the group whose owner authorizes
     * it. A stored reminder whose kind is missing here is hidden by visible().
     */
    private const array KINDS = [
        'TASK_DUE_SOON' => 'assigned', 'TASK_OVERDUE' => 'assigned',
        'TASK_REVIEW_PENDING' => 'reviews', 'TASK_RESPONSES_PENDING' => 'reviews',
        'PROJECT_DUE_SOON' => 'projects', 'PROJECT_OVERDUE' => 'projects', 'LEAVE_PENDING' => 'leaves',
        'DOCUMENT_EXPIRING' => 'documents', 'DOCUMENT_EXPIRED' => 'documents', 'PROBATION_ENDING' => 'probation',
        'PAYROLL_DUE' => 'payroll',
    ];

    /** @var array<string, ReminderSource> */
    private array $owners = [];

    /** @param iterable<ReminderSource> $sources */
    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            foreach ($source->groups() as $group) {
                if (isset($this->owners[$group])) {
                    throw new LogicException('Duplicate reminder owner: '.$group);
                }
                $this->owners[$group] = $source;
            }
        }
        foreach (self::GROUPS as $group) {
            if (! isset($this->owners[$group])) {
                throw new LogicException('Missing reminder owner: '.$group);
            }
        }
    }

    public function source(string $group): ReminderSource
    {
        return $this->owners[$group];
    }

    /** @param Collection<int, Notification> $rows
     * @return Collection<int, Notification> */
    public function visible(Access $actor, Collection $rows): Collection
    {
        $references = [];
        $subjects = [];
        foreach ($rows as $row) {
            if ($row->dedupe_key === null) {
                continue; // Ordinary event history retains its existing account-only policy.
            }
            $parts = explode(':', $row->dedupe_key, 3);
            $group = self::KINDS[$row->kind] ?? null;
            if ($group === null || $parts[0] !== $row->kind || ($parts[1] ?? '') === '') {
                continue;
            }
            $references[$row->id] = [$group, $parts[1]];
            $subjects[$group][] = $parts[1];
        }
        $allowed = [];
        foreach ($subjects as $group => $ids) {
            foreach ($this->source($group)->visibleIds($actor, $group, array_values(array_unique($ids))) as $id) {
                $allowed[$group][$id] = true;
            }
        }

        return $rows->filter(static function (Notification $row) use ($references, $allowed): bool {
            if ($row->dedupe_key === null) {
                return true;
            }
            [$group, $id] = $references[$row->id] ?? [null, null];

            return $group !== null && $id !== null && isset($allowed[$group][$id]);
        })->values();
    }
}
