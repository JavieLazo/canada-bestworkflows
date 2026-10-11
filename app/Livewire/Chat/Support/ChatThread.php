<?php

declare(strict_types=1);

namespace App\Livewire\Chat\Support;

use App\Modules\Social\Contracts\Views\MessageView;
use App\Modules\Social\Contracts\Views\PersonView;
use App\Modules\Social\Contracts\Views\ThreadView;
use App\Support\SocialFormat;
use Carbon\CarbonImmutable;

/**
 * How a thread is laid out, decided once so the Blade only draws it.
 *
 * Messenger-shaped: consecutive messages from one sender within five minutes
 * form a run. Only the run's first bubble names the sender (in a group) and
 * only its last carries the avatar, so a burst of short messages reads as one
 * turn instead of a wall of repeated headers. The time shows when you hover a
 * message.
 *
 * A quiet time label sits over the first message, over the first one of a
 * new Manila day, and over any message that comes 10 minutes or more after
 * the one before it, the way Messenger marks a conversation picking up again.
 *
 * @phpstan-type Row array{message: MessageView, stamp: string|null, first: bool, last: bool, afterReply: bool}
 */
final class ChatThread
{
    /** Minutes between two messages from one sender that still read as one turn. */
    public const int RUN_MINUTES = 5;

    /** Minutes of quiet before the thread shows the time again. */
    public const int GAP_MINUTES = 10;

    private function __construct() {}

    /**
     * The one line under your last message. It starts at "Sent", then says
     * who has seen it: one or two first names, then a count once it's three
     * or more. A direct chat only ever has one other person, so "Seen" says
     * it all there. Null when your message isn't the last one.
     */
    public static function seenLabel(ThreadView $thread): ?string
    {
        $seen = $thread->seenBy;

        if ($seen === null) {
            return null;
        }
        if ($seen === []) {
            return 'Sent';
        }
        if (! $thread->conversation->isGroup()) {
            return 'Seen';
        }

        $first = static fn (int $id): string => explode(' ', ($thread->people[$id] ?? PersonView::missing($id))->name)[0];

        return match (count($seen)) {
            1 => 'Seen by '.$first($seen[0]),
            2 => 'Seen by '.$first($seen[0]).' and '.$first($seen[1]),
            default => 'Seen by '.count($seen).' people',
        };
    }

    /**
     * @param  list<MessageView>  $messages  oldest first
     * @return list<Row>
     */
    public static function rows(array $messages, CarbonImmutable $now): array
    {
        $rows = [];

        foreach ($messages as $k => $message) {
            $previous = $messages[$k - 1] ?? null;
            $next = $messages[$k + 1] ?? null;
            $stamped = self::stamped($previous, $message);

            $rows[] = [
                'message' => $message,
                'stamp' => $stamped ? SocialFormat::threadStamp($message->createdAt, $now) : null,
                'first' => $stamped || ! self::sameTurn($previous, $message),
                'last' => ($next !== null && self::stamped($message, $next)) || ! self::sameTurn($message, $next),
                // Right under a reply, with no time label between. The reply
                // already stands apart with its own label and quote, so the
                // message after it can sit closer.
                'afterReply' => ! $stamped && $previous !== null && ! $previous->system && $previous->quote !== null,
            ];
        }

        return $rows;
    }

    /** Whether a message gets a time label over it: the first, a new day, or after a pause. */
    private static function stamped(?MessageView $previous, MessageView $message): bool
    {
        return $previous === null
            || SocialFormat::dayKey($previous->createdAt) !== SocialFormat::dayKey($message->createdAt)
            || $message->createdAt->getTimestamp() - $previous->createdAt->getTimestamp() >= self::GAP_MINUTES * 60;
    }

    private static function sameTurn(?MessageView $a, ?MessageView $b): bool
    {
        if ($a === null || $b === null || $a->system || $b->system || $a->sender?->id !== $b->sender?->id) {
            return false;
        }

        if (SocialFormat::dayKey($a->createdAt) !== SocialFormat::dayKey($b->createdAt)) {
            return false;
        }

        return abs($b->createdAt->getTimestamp() - $a->createdAt->getTimestamp()) <= self::RUN_MINUTES * 60;
    }
}
