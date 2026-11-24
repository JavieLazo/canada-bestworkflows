<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The presentation rules Community and Chat share, written once: the six
 * reactions, @mention tokens, and how long ago something was said.
 *
 * Mentions are stored as tokens, never as names. A body holds `@[key]` where
 * someone was named, `@[g:key]` where a group was, and `@[everyone]` for
 * everyone, and the names are looked up when the body is drawn. Stored names
 * go stale the day someone is renamed, and "@Maria" turns ambiguous the day a
 * second Maria is hired. `tokenize()` is the one place typed names become
 * tokens, and `segments()` the one place tokens become names again. It hands
 * them back as data, so the view prints every part through `{{ }}` and a body
 * can never inject markup.
 *
 * On time: an instant arrives as a date or a UTC ISO string, and every
 * wall-clock reading goes through LocalClock::zone(). `$now` is a parameter
 * rather than a clock read, so one feed draws every card against the same
 * instant.
 */
final class SocialFormat
{
    /**
     * How the six reactions look, in picker order. The list itself belongs to
     * the Social module (Vocabulary::REACTIONS); a test keeps the two in step.
     *
     * @var array<string, array{emoji: string, label: string}>
     */
    public const array REACTIONS = [
        'LIKE' => ['emoji' => "\u{1F44D}", 'label' => 'Like'],
        'LOVE' => ['emoji' => "\u{2764}\u{FE0F}", 'label' => 'Love'],
        'CELEBRATE' => ['emoji' => "\u{1F389}", 'label' => 'Celebrate'],
        'SUPPORT' => ['emoji' => "\u{1F64C}", 'label' => 'Support'],
        'INSIGHTFUL' => ['emoji' => "\u{1F4A1}", 'label' => 'Insightful'],
        'FUNNY' => ['emoji' => "\u{1F602}", 'label' => 'Funny'],
    ];

    private function __construct() {}

    /**
     * The most-used reactions on something, most first, for the stacked
     * summary under a post or bubble. Zero counts never show.
     *
     * @param  array<string, int>  $counts  reaction type => how many
     * @return list<string> reaction types
     */
    public static function topReactions(array $counts, int $limit = 3): array
    {
        $counts = array_filter($counts, static fn (int $n): bool => $n > 0);
        arsort($counts);

        return array_slice(array_keys($counts), 0, $limit);
    }

    /** What a stored `@[everyone]` reads as, and what someone types to get one. */
    public const string EVERYONE = 'everyone';

    /** Any stored mention: a person, a group or everyone. */
    private const string TOKEN = '/(@\[(?:\d+|g:[A-Za-z0-9-]{1,40}|everyone|[a-z0-9-]{1,40})\])/';

    /**
     * Typed names → tokens: `@Full Name` → `@[key]` for every person the
     * writer can name, `@Group name` → `@[g:key]` for every group they can,
     * and `@everyone` → `@[everyone]` when they may use it. Letter case
     * doesn't matter. Longest names first, so "@Maria Santos Jr." is never
     * read as "@Maria Santos" followed by " Jr.", and a person wins over a
     * group with the same name. Anything typed to look like a token is
     * disarmed first, so only a name the writer may use becomes one.
     *
     * @param  array<array-key, string>  $names  account id => display name
     * @param  array<array-key, string>  $groups  group id => group name
     */
    public static function tokenize(string $body, array $names, array $groups = [], bool $everyone = false): string
    {
        $body = self::disarm($body);
        $needles = [];
        foreach ($groups as $key => $name) {
            $needles['@'.$name] = '@[g:'.$key.']';
        }
        foreach ($names as $key => $name) {
            $needles['@'.$name] = '@['.$key.']';
        }
        uksort($needles, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($needles as $needle => $token) {
            // Only a whole name: "@Ana" never eats the start of "@Anabelle".
            if (trim($needle) !== '@' && mb_stripos($body, $needle) !== false) {
                $body = (string) preg_replace('/'.preg_quote($needle, '/').'(?![\p{L}\p{N}_])/iu', $token, $body);
            }
        }

        if ($everyone) {
            $body = (string) preg_replace('/@everyone\b/i', '@['.self::EVERYONE.']', $body);
        }

        return $body;
    }

    /**
     * Text typed to look like a stored mention, such as `@[12]` or
     * `@[everyone]`, loses its power: a word joiner (U+2060, invisible) goes
     * between the @ and the bracket. It reads exactly as typed, but it's plain
     * text now. Without this, typing the stored form of @everyone would ring
     * every bell in the company, whoever typed it.
     */
    public static function disarm(string $body): string
    {
        return (string) preg_replace('/@(?=\[(?:\d+|g:[A-Za-z0-9-]{1,40}|everyone|[a-z0-9-]{1,40})\])/', "@\u{2060}", $body);
    }

    /**
     * The account ids a body mentions, each once.
     *
     * @return list<int>
     */
    public static function mentions(string $body): array
    {
        preg_match_all('/@\[(\d+)\]/', $body, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /**
     * The group ids a body mentions, each once.
     *
     * @return list<string>
     */
    public static function groupMentions(string $body): array
    {
        preg_match_all('/@\[g:([A-Za-z0-9-]{1,40})\]/', $body, $m);

        return array_values(array_unique($m[1]));
    }

    public static function mentionsEveryone(string $body): bool
    {
        return str_contains($body, '@['.self::EVERYONE.']');
    }

    /**
     * A body cut into printable runs. A token naming someone the reader can't
     * resolve prints as "@Someone" (or "@A group") rather than leaking the key.
     *
     * @param  array<array-key, string>  $names  account id => display name
     * @param  array<array-key, string>  $groups  group id => group name
     * @return list<array{kind: 'text'|'mention'|'group'|'everyone', text: string, key?: string}>
     */
    public static function segments(string $body, array $names, array $groups = []): array
    {
        $parts = preg_split(self::TOKEN, $body, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = [];

        foreach ($parts as $part) {
            if (preg_match('/^@\[(.+)\]$/', $part, $m) !== 1 || preg_match(self::TOKEN, $part) !== 1) {
                $segments[] = ['kind' => 'text', 'text' => $part];

                continue;
            }

            $key = $m[1];
            $segments[] = match (true) {
                $key === self::EVERYONE => ['kind' => 'everyone', 'text' => '@'.self::EVERYONE],
                str_starts_with($key, 'g:') => ['kind' => 'group', 'text' => '@'.($groups[substr($key, 2)] ?? 'A group'), 'key' => substr($key, 2)],
                default => ['kind' => 'mention', 'text' => '@'.($names[$key] ?? 'Someone'), 'key' => $key],
            };
        }

        return $segments;
    }

    /**
     * A body as plain text, with tokens read back as names. For list previews
     * and reply quotes.
     *
     * @param  array<array-key, string>  $names  account id => display name
     * @param  array<array-key, string>  $groups  group id => group name
     */
    public static function plain(string $body, array $names, array $groups = []): string
    {
        return implode('', array_column(self::segments($body, $names, $groups), 'text'));
    }

    /**
     * Feed time: `Just now`, `12m`, `3h`, `4d`, then the date (`Sep 14`). The
     * year shows only once it is not this year's.
     */
    public static function ago(DateTimeInterface|string $at, CarbonImmutable $now): string
    {
        $at = self::instant($at);
        $minutes = (int) floor(max(0, $now->getTimestamp() - $at->getTimestamp()) / 60);

        return match (true) {
            $minutes < 1 => 'Just now',
            $minutes < 60 => $minutes.'m',
            $minutes < 1440 => intdiv($minutes, 60).'h',
            $minutes < 10080 => intdiv($minutes, 1440).'d',
            default => self::day($at, $now, 'M j'),
        };
    }

    /**
     * Conversation-list time: the clock time today, `Yesterday`, the weekday
     * within the week, then the date.
     */
    public static function listTime(DateTimeInterface|string $at, CarbonImmutable $now): string
    {
        $at = self::instant($at)->setTimezone(LocalClock::zone());
        $today = $now->setTimezone(LocalClock::zone())->startOfDay();

        return match (true) {
            $at >= $today => $at->format('g:i A'),
            $at >= $today->subDay() => 'Yesterday',
            $at >= $today->subDays(6) => $at->format('D'),
            default => self::day($at, $now, 'M j'),
        };
    }

    /**
     * The quiet label a chat thread puts over a message after a pause: `9:35
     * PM` today, `Yesterday 9:35 PM`, `Monday 9:35 PM` within the week, then
     * `Sep 14, 9:35 PM`.
     */
    public static function threadStamp(DateTimeInterface|string $at, CarbonImmutable $now): string
    {
        $at = self::instant($at)->setTimezone(LocalClock::zone());
        $today = $now->setTimezone(LocalClock::zone())->startOfDay();

        return match (true) {
            $at >= $today => $at->format('g:i A'),
            $at >= $today->subDay() => 'Yesterday '.$at->format('g:i A'),
            $at >= $today->subDays(6) => $at->format('l g:i A'),
            default => self::day($at, $now, 'M j').', '.$at->format('g:i A'),
        };
    }

    /** The local calendar day an instant falls on, for grouping a thread by day. */
    public static function dayKey(DateTimeInterface|string $at): string
    {
        return self::instant($at)->setTimezone(LocalClock::zone())->format('Y-m-d');
    }

    /** `9:42 AM`, local. */
    public static function clock(DateTimeInterface|string $at): string
    {
        return self::instant($at)->setTimezone(LocalClock::zone())->format('g:i A');
    }

    /** The full instant for a tooltip, in the `M d, Y, g:i A` the notification bell prints. */
    public static function stamp(DateTimeInterface|string $at): string
    {
        return self::instant($at)->setTimezone(LocalClock::zone())->format('M d, Y, g:i A');
    }

    private static function instant(DateTimeInterface|string $at): CarbonImmutable
    {
        return $at instanceof DateTimeInterface ? CarbonImmutable::instance($at) : CarbonImmutable::parse($at);
    }

    private static function day(CarbonImmutable $at, CarbonImmutable $now, string $format): string
    {
        $at = $at->setTimezone(LocalClock::zone());

        return $at->year === $now->setTimezone(LocalClock::zone())->year
            ? $at->format($format)
            : $at->format($format.', Y');
    }
}
