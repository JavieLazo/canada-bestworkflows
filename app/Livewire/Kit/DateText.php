<?php

declare(strict_types=1);

namespace App\Livewire\Kit;

/** Compatibility adapter; implementation belongs to \App\Support\DateText. */
final class DateText
{
    /** `2026-08-20` → `20 Aug 2026`. Empty in, empty out. (DateField.tsx:30-36) */
    public static function toDisplay(string $key): string
    {
        return \App\Support\DateText::toDisplay($key);
    }

    /**
     * Accepts what people actually type: `20 Aug 2026`, `20/08/2026`,
     * `2026-08-20`, `20 August 2026`. Returns the storage key, or "" when it
     * is not a real date — the 31st of a 30-day month included.
     * (DateField.tsx:42-70)
     */
    public static function toKey(string $text): string
    {
        return \App\Support\DateText::toKey($text);
    }
}
