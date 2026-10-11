<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Models\Notification;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Account-owned event history plus current owner authorization for stored reminder details. */
final class Inbox
{
    public function __construct(private readonly ReminderCatalog $reminders) {}

    /** Raw account scope is internal plumbing; display callers must use latest/summary. */
    /** @return Builder<Notification>|null */
    public function own(Access $actor): ?Builder
    {
        return $actor->isSignedIn() ? Notification::query()->where('user_id', (int) $actor->userId) : null;
    }

    /** @return Collection<int, Notification> */
    public function latest(Access $actor, int $limit): Collection
    {
        $query = $this->own($actor);
        $limit = max(0, min(500, $limit));
        $out = new Collection;
        if ($query === null || $limit === 0) {
            return $out;
        }
        $batchSize = min($limit, ReminderSource::BATCH_SIZE);
        foreach ($query->orderByDesc('created_at')->orderByDesc('id')->lazy($batchSize)->chunk($batchSize) as $chunk) {
            foreach ($this->reminders->visible($actor, $chunk->collect()) as $row) {
                $out->push($row);
                if ($out->count() >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /** Counts stream only reminder references; ordinary event history stays one aggregate.
     * @return array{unread: int, stamp: string} */
    public function summary(Access $actor): array
    {
        $query = $this->own($actor);
        if ($query === null) {
            return ['unread' => 0, 'stamp' => 'signed-out'];
        }
        $events = (clone $query)->whereNull('dedupe_key')->toBase()
            ->selectRaw('COUNT(*) AS total, COUNT(read_at) AS seen, MAX(created_at) AS newest')->first();
        $unread = (int) ($events->total ?? 0) - (int) ($events->seen ?? 0);
        $hash = hash_init('sha256');
        hash_update($hash, json_encode($events, JSON_THROW_ON_ERROR));
        foreach ($query->whereNotNull('dedupe_key')->select(['id', 'kind', 'dedupe_key', 'read_at'])->lazyById(ReminderSource::BATCH_SIZE)->chunk(ReminderSource::BATCH_SIZE) as $chunk) {
            foreach ($this->reminders->visible($actor, $chunk->collect()) as $row) {
                $unread += $row->read_at === null ? 1 : 0;
                hash_update($hash, $row->id.':'.($row->read_at?->format('Y-m-d H:i:s.v') ?? '').';');
            }
        }

        return ['unread' => $unread, 'stamp' => hash_final($hash)];
    }

    public function markRead(Access $actor, string $id): ?string
    {
        $notification = $this->own($actor)?->whereKey($id)->first();
        if ($notification === null || $this->reminders->visible($actor, collect([$notification]))->isEmpty()) {
            return null;
        }
        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        return $notification->href;
    }

    public function markAllRead(Access $actor, ?int $windowLimit = null): void
    {
        $own = $this->own($actor);
        if ($own === null) {
            return;
        }
        if ($windowLimit !== null) {
            $own->whereIn('id', $this->latest($actor, $windowLimit)->pluck('id'))->whereNull('read_at')->update(['read_at' => now()]);

            return;
        }
        foreach ($own->whereNull('read_at')->select(['id', 'kind', 'dedupe_key'])->lazyById(ReminderSource::BATCH_SIZE)->chunk(ReminderSource::BATCH_SIZE) as $chunk) {
            $ids = $this->reminders->visible($actor, $chunk->collect())->pluck('id');
            $this->own($actor)?->whereKey($ids)->whereNull('read_at')->update(['read_at' => now()]);
        }
    }
}
