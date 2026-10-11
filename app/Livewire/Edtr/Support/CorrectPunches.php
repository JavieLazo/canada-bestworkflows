<?php

declare(strict_types=1);

namespace App\Livewire\Edtr\Support;

use App\Support\Access;
use RuntimeException;

/** Compatibility adapter; business logic belongs to \App\Modules\Timekeeping\Application\Commands\CorrectPunches. */
final class CorrectPunches
{
    /**
     * Apply a correction. Throws RuntimeException with the user-facing message
     * on any refusal — the caller shows it in the dialog, exactly where the
     * reference surfaced the API error.
     *
     * The CALLER must already have checked `mayReadAllData(EDTR)` — the port
     * of `requireAllDataModule("EDTR")` — and it is re-checked here so this
     * service cannot be reached around that gate.
     *
     * @param  array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}  $slots
     * @param  array<array-key, mixed>  $nextDay  the "Next day" tick per slot key
     */
    public static function apply(Access $actor, string $employeeId, string $date, array $slots, array $nextDay, ?string $note): void
    {
        \App\Modules\Timekeeping\Application\Commands\CorrectPunches::apply($actor, $employeeId, $date, $slots, $nextDay, $note);
    }
}
