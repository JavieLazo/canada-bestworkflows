<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Credits\LeaveEligibility;

/**
 * WHO MAY USE A LEAVE TYPE (LeaveEligibility): every status and anyone
 * unless HR chose; an employee is judged on the status and sex their record
 * holds today, and one whose record names no status on the list, or no sex,
 * is not eligible for a type restricted by it. The day they BECAME eligible
 * is where the run of eligible statuses reaching today began — the date
 * hired when it reaches back to their first status.
 */
it('lets everyone use a type nobody restricted', function () {
    $rule = new LeaveEligibility;

    expect($rule->isEveryone())->toBeTrue()
        ->and($rule->allows(null, null))->toBeTrue()
        ->and($rule->since('2024-01-01', [['from' => '2024-01-01', 'status_id' => null]], null))->toBe('2024-01-01')
        ->and($rule->describe([]))->toBe('everyone');
});

it('judges an employment status restriction on the status today, and refuses a record with no status on the list', function () {
    $rule = new LeaveEligibility(['st-perm', 'st-casual']);

    expect($rule->allows('st-perm', null))->toBeTrue()
        ->and($rule->refusedBy('st-jo', 'Male'))->toBe(LeaveEligibility::BY_STATUS)
        ->and($rule->refusedBy(null, 'Female'))->toBe(LeaveEligibility::BY_STATUS)
        ->and($rule->describe(['st-perm' => 'Permanent', 'st-casual' => 'Casual']))->toBe('Permanent, Casual');
});

it('judges a sex restriction against the record as stored, case aside, and refuses a record with none', function () {
    $rule = new LeaveEligibility(null, LeaveEligibility::FEMALE);

    expect($rule->allows(null, 'Female'))->toBeTrue()
        ->and($rule->allows(null, ' female '))->toBeTrue()
        ->and($rule->refusedBy(null, 'Male'))->toBe(LeaveEligibility::BY_SEX)
        ->and($rule->refusedBy(null, null))->toBe(LeaveEligibility::BY_SEX)
        // The status is judged first.
        ->and((new LeaveEligibility(['st-perm'], LeaveEligibility::MALE))->refusedBy('st-jo', 'Female'))->toBe(LeaveEligibility::BY_STATUS)
        ->and($rule->describe([]))->toBe('female employees only');
});

it('dates eligibility from the start of the run of eligible statuses that reaches today', function () {
    $rule = new LeaveEligibility(['st-perm', 'st-casual']);
    $jo = ['from' => '2024-01-01', 'status_id' => 'st-jo'];

    // Job Order, then Permanent: eligible from the day of the change.
    expect($rule->since('2024-01-01', [$jo, ['from' => '2026-03-16', 'status_id' => 'st-perm']], null))->toBe('2026-03-16')
        // Casual then Permanent, both eligible: from the date hired.
        ->and($rule->since('2024-01-01', [['from' => '2024-01-01', 'status_id' => 'st-casual'], ['from' => '2025-06-01', 'status_id' => 'st-perm']], null))->toBe('2024-01-01')
        // Eligible, out, eligible again: only the run that reaches today counts.
        ->and($rule->since('2024-01-01', [['from' => '2024-01-01', 'status_id' => 'st-perm'], ['from' => '2025-01-01', 'status_id' => 'st-jo'], ['from' => '2025-09-01', 'status_id' => 'st-casual']], null))->toBe('2025-09-01')
        // Not eligible today: no day at all.
        ->and($rule->since('2024-01-01', [['from' => '2024-01-01', 'status_id' => 'st-perm'], ['from' => '2026-01-01', 'status_id' => 'st-jo']], null))->toBeNull()
        ->and($rule->since('2024-01-01', [], null))->toBeNull();

    // A sex restriction never moves the day; it only answers whether there is one.
    $female = new LeaveEligibility(['st-perm'], LeaveEligibility::FEMALE);
    expect($female->since('2024-01-01', [$jo, ['from' => '2026-03-16', 'status_id' => 'st-perm']], 'Female'))->toBe('2026-03-16')
        ->and($female->since('2024-01-01', [$jo, ['from' => '2026-03-16', 'status_id' => 'st-perm']], 'Male'))->toBeNull();
});

it('validates the typed choice against the Employment list and reads stored columns leniently', function () {
    $known = ['st-perm', 'st-jo'];

    expect(LeaveEligibility::error(['status_mode' => 'ALL', 'sex' => ''], $known))->toBeNull()
        ->and(LeaveEligibility::error([], $known))->toBeNull()
        ->and(LeaveEligibility::error(['status_mode' => 'SOME', 'statuses' => ['st-perm'], 'sex' => 'FEMALE'], $known))->toBeNull()
        ->and(LeaveEligibility::error(['status_mode' => 'SOME', 'statuses' => []], $known))->toContain('at least one employment status')
        ->and(LeaveEligibility::error(['status_mode' => 'SOME', 'statuses' => ['st-gone']], $known))->toContain('from the Employment list')
        ->and(LeaveEligibility::error(['status_mode' => 'MOST'], $known))->toContain('who may use')
        ->and(LeaveEligibility::error(['sex' => 'OTHER'], $known))->toContain('female employees only');

    $rule = LeaveEligibility::fromInput(['status_mode' => 'SOME', 'statuses' => ['st-perm', 'st-perm'], 'sex' => 'MALE']);
    expect($rule->statuses)->toBe(['st-perm'])->and($rule->sex)->toBe('MALE')
        ->and($rule->toArray())->toBe(['eligible_statuses' => ['st-perm'], 'eligible_sex' => 'MALE'])
        // Under ALL the ticks are ignored.
        ->and(LeaveEligibility::fromInput(['status_mode' => 'ALL', 'statuses' => ['st-perm']])->isEveryone())->toBeTrue()
        ->and(LeaveEligibility::fromColumns('junk', 'OTHER')->isEveryone())->toBeTrue()
        ->and(LeaveEligibility::fromColumns([], null)->isEveryone())->toBeTrue()
        ->and(LeaveEligibility::fromColumns(['st-jo', 7], 'FEMALE')->statuses)->toBe(['st-jo']);
});
