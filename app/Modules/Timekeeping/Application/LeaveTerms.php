<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;

/**
 * What applies to ONE employee on a date under the leave rules: the policy
 * the earning job and payroll read — the same for everybody — the general
 * rule version it came from, the day this employee acquired each leave
 * type that has a waiting period, and the day they became eligible for
 * each type not everyone may use (LeaveEligibilities). A type is the
 * employee's to use on a date only from both days on.
 */
final class LeaveTerms
{
    /**
     * @param  string  $sourceId  the general rule version in force
     * @param  array<string, ?string>  $acquired  token => the day acquired ('Y-m-d'), or null while it is not; only types with a waiting period appear
     * @param  array<string, ?string>  $eligibleFrom  token => the day the employee became eligible ('Y-m-d'), or null while they are not; only types not everyone may use appear
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly LeavePolicy $policy,
        public readonly array $acquired,
        public readonly array $eligibleFrom = [],
    ) {}

    /** Whether the type waits on a day of acquisition at all. */
    public function gated(string $token): bool
    {
        return array_key_exists($token, $this->acquired);
    }

    /** The day the type was acquired; null for a type that does not wait, or is not acquired yet. */
    public function acquiredOn(string $token): ?string
    {
        return $this->acquired[$token] ?? null;
    }

    /** Whether the type is restricted to some employees (LeaveEligibility). */
    public function restricted(string $token): bool
    {
        return array_key_exists($token, $this->eligibleFrom);
    }

    /** The day the employee became eligible for a restricted type; null for a type everyone may use, or one they may not. */
    public function eligibleFrom(string $token): ?string
    {
        return $this->eligibleFrom[$token] ?? null;
    }

    /**
     * Whether the employee may not use the type on `$date` because of who
     * they are: not eligible at all, or eligible only from a later day.
     */
    public function closedFor(string $token, string $date): bool
    {
        if (! $this->restricted($token)) {
            return false;
        }
        $since = $this->eligibleFrom($token);

        return $since === null || $since > $date;
    }

    /** Whether the type is the employee's to use on `$date`. */
    public function open(string $token, string $date): bool
    {
        if ($this->closedFor($token, $date)) {
            return false;
        }
        if (! $this->gated($token)) {
            return true;
        }
        $day = $this->acquiredOn($token);

        return $day !== null && $day <= $date;
    }

    /**
     * The first day an OPEN type is the employee's: the later of the day
     * acquired and the day they became eligible; null for a type that
     * neither waits nor is restricted, which has no first day of its own.
     */
    public function openFrom(string $token): ?string
    {
        $days = array_values(array_filter([$this->acquiredOn($token), $this->eligibleFrom($token)], static fn (?string $day): bool => $day !== null));

        return $days === [] ? null : max($days);
    }
}
