<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

/**
 * WHO MAY USE A LEAVE TYPE, as HR sets it on Leave Settings: every
 * employment status or the chosen ones (Employment list ids), and anyone or
 * only women or only men. A type nobody restricted is everyone's, which is
 * what every type starts at.
 *
 * An employee is judged on the status their record holds TODAY: one whose
 * record names no status on the list, or no sex, is not eligible for a type
 * restricted by it. The day they BECAME eligible is the start of the run of
 * eligible statuses that reaches today on their status timeline — the date
 * hired when the run reaches back to their first recorded status. A leave
 * type's waiting period counts from that day, and the type is not theirs
 * on any date before it.
 *
 * The pure shape, its validation and the judgment; LeaveEligibilities
 * applies it to employees. Nothing here is checked against a law: who may
 * use a type is HR's decision.
 */
final class LeaveEligibility
{
    public const string FEMALE = 'FEMALE';

    public const string MALE = 'MALE';

    public const array SEXES = [self::FEMALE, self::MALE];

    /** @var array<string, string> the employees a sex restriction leaves the type to */
    public const array SEX_LABELS = [self::FEMALE => 'Female employees only', self::MALE => 'Male employees only'];

    /** The form's "who may use it" choice: every employment status, or only the ticked ones. */
    public const string STATUS_ALL = 'ALL';

    public const string STATUS_SOME = 'SOME';

    /** Why an employee is not eligible: their employment status, or their sex. */
    public const string BY_STATUS = 'STATUS';

    public const string BY_SEX = 'SEX';

    /**
     * @param  list<string>|null  $statuses  the Employment list ids whose employees may use the type; null for every status
     * @param  string|null  $sex  FEMALE or MALE for a type only one sex may use; null for anyone
     */
    public function __construct(
        public readonly ?array $statuses = null,
        public readonly ?string $sex = null,
    ) {}

    /** Whether the type is open to everyone: no status and no sex restriction. */
    public function isEveryone(): bool
    {
        return $this->statuses === null && $this->sex === null;
    }

    /**
     * Why an employee with this status and sex may not use the type — the
     * status first, then the sex — or null when they may.
     *
     * @param  string|null  $statusId  the Employment list id their record's status names; null when it names none
     * @param  string|null  $sex  their record's sex, as stored ("Female", "Male"); null when none is recorded
     */
    public function refusedBy(?string $statusId, ?string $sex): ?string
    {
        if (! $this->statusAllows($statusId)) {
            return self::BY_STATUS;
        }
        if ($this->sex !== null && strtoupper(trim((string) $sex)) !== $this->sex) {
            return self::BY_SEX;
        }

        return null;
    }

    public function allows(?string $statusId, ?string $sex): bool
    {
        return $this->refusedBy($statusId, $sex) === null;
    }

    /** Whether the status alone lets the employee use the type. */
    public function statusAllows(?string $statusId): bool
    {
        return $this->statuses === null || ($statusId !== null && in_array($statusId, $this->statuses, true));
    }

    /**
     * The day the employee became eligible, or null while they are not.
     * The timeline is the employee's statuses, oldest first, each from the
     * day it took effect, ending with the status their record holds today;
     * the first entry runs from the date hired. A sex restriction does not
     * move the day: a record's sex is not dated.
     *
     * @param  list<array{from: string, status_id: ?string}>  $timeline
     */
    public function since(string $hired, array $timeline, ?string $sex): ?string
    {
        if ($timeline === []) {
            return null;
        }
        $current = $timeline[array_key_last($timeline)]['status_id'];
        if (! $this->allows($current, $sex)) {
            return null;
        }
        $since = $hired;
        for ($i = count($timeline) - 1; $i >= 0; $i--) {
            if (! $this->statusAllows($timeline[$i]['status_id'])) {
                // The status after this one is where the eligible run began.
                $since = $timeline[$i + 1]['from'];
                break;
            }
        }

        return max($hired, $since);
    }

    /**
     * Why the typed choice is refused, or null when it is acceptable.
     * `status_mode` is ALL or SOME; under SOME, `statuses` holds at least one
     * id, each one of `$known` (the Employment list's ids). `sex` is blank
     * for anyone, or FEMALE or MALE.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $known
     */
    public static function error(array $input, array $known): ?string
    {
        $mode = $input['status_mode'] ?? self::STATUS_ALL;
        if (! in_array($mode, [self::STATUS_ALL, self::STATUS_SOME], true)) {
            return 'Choose who may use this leave type.';
        }
        if ($mode === self::STATUS_SOME) {
            $statuses = $input['statuses'] ?? null;
            if (! is_array($statuses) || $statuses === []) {
                return 'Choose at least one employment status that may use this leave type.';
            }
            foreach ($statuses as $id) {
                if (! is_string($id) || ! in_array($id, $known, true)) {
                    return 'Choose employment statuses from the Employment list.';
                }
            }
        }
        $sex = $input['sex'] ?? '';
        if ($sex !== '' && ! in_array($sex, self::SEXES, true)) {
            return 'Choose whether the leave type is for anyone, female employees only or male employees only.';
        }

        return null;
    }

    /** @param  array<string, mixed>  $input  a choice error() accepted */
    public static function fromInput(array $input): self
    {
        $some = ($input['status_mode'] ?? self::STATUS_ALL) === self::STATUS_SOME;
        $statuses = $some ? array_values(array_unique(array_map(strval(...), (array) ($input['statuses'] ?? [])))) : null;
        $sex = in_array($input['sex'] ?? null, self::SEXES, true) ? (string) $input['sex'] : null;

        return new self($statuses === [] ? null : $statuses, $sex);
    }

    /**
     * The stored columns read leniently: anything but a list of strings is
     * every status, anything but FEMALE or MALE is anyone.
     */
    public static function fromColumns(mixed $statuses, mixed $sex): self
    {
        $ids = null;
        if (is_array($statuses) && $statuses !== []) {
            $ids = array_values(array_filter($statuses, is_string(...)));
            $ids = $ids === [] ? null : $ids;
        }

        return new self($ids, in_array($sex, self::SEXES, true) ? (string) $sex : null);
    }

    /**
     * The columns on `leave_types`.
     *
     * @return array{eligible_statuses: list<string>|null, eligible_sex: string|null}
     */
    public function toArray(): array
    {
        return ['eligible_statuses' => $this->statuses, 'eligible_sex' => $this->sex];
    }

    /**
     * "Permanent, Casual · female employees only", "everyone": who may use
     * the type, in words. A status no longer on the list is left out.
     *
     * @param  array<string, string>  $statusNames  Employment list id => name
     */
    public function describe(array $statusNames): string
    {
        if ($this->isEveryone()) {
            return 'everyone';
        }
        $parts = [];
        if ($this->statuses !== null) {
            $names = array_values(array_filter(array_map(static fn (string $id): ?string => $statusNames[$id] ?? null, $this->statuses)));
            $parts[] = $names === [] ? 'no employment status on the list' : implode(', ', $names);
        }
        if ($this->sex !== null) {
            $parts[] = strtolower(self::SEX_LABELS[$this->sex]);
        }

        return implode(' · ', $parts);
    }
}
