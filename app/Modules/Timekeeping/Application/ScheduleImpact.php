<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

/**
 * What a schedule change will do, shown BEFORE it is saved (D4, D6 and D7):
 *
 *   - `effective`: the date it takes effect, per employee;
 *   - `deferred`: why that is not today, where today's cutoff has passed;
 *   - `restDays`: dates inside a temporary schedule that change between
 *     working and rest because the weekly schedule under it changed;
 *   - `drafts`: payroll drafts whose amounts can move, because a month's
 *     daily rate divides by that whole month's working days.
 *
 * `token()` names exactly this impact. A save that needs confirming must
 * carry the token of what the person was shown, so a change whose impact
 * moved in between is shown again instead of saved.
 */
final readonly class ScheduleImpact
{
    /**
     * @param  array<string, string>  $effective  employee id => 'Y-m-d'
     * @param  array<string, string>  $deferred  employee id => the reason today was not possible
     * @param  list<array{employee_id: string, date: string, works: bool}>  $restDays  `works` is what the date becomes
     * @param  list<array{employee_id: string, code: string, name: string, period_start: string, period_end: string}>  $drafts
     */
    public function __construct(
        public array $effective = [],
        public array $deferred = [],
        public array $restDays = [],
        public array $drafts = [],
    ) {}

    /** Whether anything was written for anyone. */
    public function changed(): bool
    {
        return $this->effective !== [];
    }

    public function needsConfirmation(): bool
    {
        return $this->deferred !== [] || $this->restDays !== [] || $this->drafts !== [];
    }

    public function token(): string
    {
        return hash('sha256', (string) json_encode([$this->effective, $this->deferred, $this->restDays, $this->drafts]));
    }

    /** The one date the change takes effect, when it is the same for everyone. */
    public function date(): ?string
    {
        $dates = array_values(array_unique($this->effective));

        return count($dates) === 1 ? $dates[0] : null;
    }
}
