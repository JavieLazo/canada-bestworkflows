<?php

declare(strict_types=1);

namespace App\Livewire\Community\Support;

/**
 * How the Community screen colours its tags. Which tags exist is data now,
 * managed by moderators. Which colours a tag can take is the Social module's
 * list (Vocabulary::TAG_TONES), and a test keeps the two in step.
 *
 * Tags reuse the pill tiers from app.css rather than inventing colours. The
 * starting five were picked by what a reader should do: an announcement is
 * the brand tint (read it), kudos is green (nothing owed), a question is
 * amber (someone is waiting on an answer), and the social ones stay quiet.
 */
final class CommunityLabels
{
    /**
     * Tone => what the tag menu calls it, the pill class that draws it, and
     * the dot that stands for it in a menu. The class names are written out
     * in full so Tailwind finds them. `info` is the app's grey pill with
     * ordinary text, not a blue one, so it's "Plain".
     *
     * @var array<string, array{label: string, pill: string, dot: string}>
     */
    public const array TONES = [
        'muted' => ['label' => 'Grey', 'pill' => 'pill-muted', 'dot' => 'bg-muted-foreground'],
        'primary' => ['label' => 'Brand blue', 'pill' => 'pill-primary', 'dot' => 'bg-primary'],
        'info' => ['label' => 'Plain', 'pill' => 'pill-info', 'dot' => 'bg-foreground'],
        'success' => ['label' => 'Green', 'pill' => 'pill-success', 'dot' => 'bg-success'],
        'caution' => ['label' => 'Amber', 'pill' => 'pill-caution', 'dot' => 'bg-warning'],
        'danger' => ['label' => 'Red', 'pill' => 'pill-danger', 'dot' => 'bg-danger'],
    ];

    private function __construct() {}

    public static function pill(string $tone): string
    {
        return self::TONES[$tone]['pill'] ?? 'pill-muted';
    }

    public static function dot(string $tone): string
    {
        return self::TONES[$tone]['dot'] ?? 'bg-muted-foreground';
    }
}
