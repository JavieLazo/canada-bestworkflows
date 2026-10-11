<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Support\Access;

/** Owners supply bounded, authorized facts and validate stored reminder references. */
interface ReminderSource
{
    public const int BATCH_SIZE = 200;

    /** @return list<string> */
    public function groups(): array;

    /** @return iterable<list<array{dedupeKey: string, kind: string, title: string, body: string|null, href: string}>> */
    public function candidates(Access $actor, string $today, string $group): iterable;

    /** @param list<string> $ids
     * @return list<string> */
    public function visibleIds(Access $actor, string $group, array $ids): array;
}
