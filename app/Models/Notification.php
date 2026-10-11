<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An in-app notification addressed to one account. Unique on
 * `(user_id, dedupe_key)`.
 *
 * `dedupe_key` is unique per (rule, record, trigger) for the reminders the
 * `reminders:sweep` cron derives (`ReminderSweep::build()`) — never on page
 * load. Every sweep re-derives them, and the key plus that unique index is
 * what stops the same reminder landing twice on a later sweep. It is NULL for
 * event notifications, which fire from a write that only happens once.
 *
 * MySQL/InnoDB and PostgreSQL AGREE that a UNIQUE index permits unlimited rows
 * where any indexed column is NULL, so event notifications still repeat freely
 * while reminders dedupe. Port that literally — do NOT "improve" `dedupe_key`
 * into a NOT NULL column with a sentinel, which would break both halves.
 *
 * This is also the ONE table where Laravel's `upsert()` is safe (a single
 * unique plus the primary key) — but only if the ULID is left out of the
 * values, or the primary key becomes a second collision target.
 *
 * `user_id` is the addressee and is server-assigned by the fan-out; it is
 * never mass-assigned. `kind` is one of the 19 strings in
 * `src/lib/notifications.ts` or one ems added on top (the TASK_RESPONSE_*
 * kinds among them — see Platform\Notifications\Notifier), and `href` is
 * where clicking it should go.
 *
 * @property string $id
 * @property int $user_id
 * @property string $kind
 * @property string $title
 * @property string|null $body
 * @property string|null $href
 * @property string|null $dedupe_key
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 */
class Notification extends BaseModel
{
    /** @var string */
    protected $table = 'notifications';

    /** @var list<string> */
    protected $fillable = [
        'kind',
        'title',
        'body',
        'href',
        'dedupe_key',
        'read_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
