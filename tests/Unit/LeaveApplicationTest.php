<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Absence\LeaveApplication;

/**
 * The details of leave a request keeps follow the leave type's section:
 * where a section asks, its choice is required, and so is the text a
 * choice says to specify; a section of NONE keeps nothing whatever was
 * sent; a choice that asks no text keeps none. Commutation is "not
 * requested" unless said, and an unknown value is refused.
 */

/*
|--------------------------------------------------------------------------
| Details of leave
|--------------------------------------------------------------------------
*/

it('keeps what each section asks, and only that', function (string $section, ?string $choice, ?string $note, array $expected) {
    expect(LeaveApplication::details($section, $choice, $note))->toBe($expected);
})->with([
    'none keeps nothing' => [LeaveApplication::NONE, LeaveApplication::ABROAD, 'Japan', [null, null, null]],
    'unknown section keeps nothing' => ['ELSEWHERE', LeaveApplication::ABROAD, 'Japan', [null, null, null]],
    'within the Philippines keeps where, trimmed' => [LeaveApplication::VACATION, LeaveApplication::WITHIN_PH, '  Baguio ', [LeaveApplication::WITHIN_PH, 'Baguio', null]],
    'within the Philippines may leave it blank' => [LeaveApplication::VACATION, LeaveApplication::WITHIN_PH, '  ', [LeaveApplication::WITHIN_PH, null, null]],
    'abroad keeps where, trimmed' => [LeaveApplication::VACATION, LeaveApplication::ABROAD, '  Japan ', [LeaveApplication::ABROAD, 'Japan', null]],
    'in hospital keeps the illness' => [LeaveApplication::SICK, LeaveApplication::IN_HOSPITAL, 'Dengue', [LeaveApplication::IN_HOSPITAL, 'Dengue', null]],
    'out patient keeps the illness' => [LeaveApplication::SICK, LeaveApplication::OUT_PATIENT, 'Flu', [LeaveApplication::OUT_PATIENT, 'Flu', null]],
    'women has its one choice' => [LeaveApplication::WOMEN, null, 'Myoma', [LeaveApplication::ILLNESS, 'Myoma', null]],
    'women ignores another choice sent' => [LeaveApplication::WOMEN, LeaveApplication::ABROAD, 'Myoma', [LeaveApplication::ILLNESS, 'Myoma', null]],
    'study keeps the purpose alone' => [LeaveApplication::STUDY, LeaveApplication::BAR_BOARD, 'ignored', [LeaveApplication::BAR_BOARD, null, null]],
]);

it('refuses a missing choice, a choice of another section, a missing text and an over-long one', function (string $section, ?string $choice, ?string $note, string $error) {
    expect(LeaveApplication::details($section, $choice, $note))->toBe([null, null, $error]);
})->with([
    'vacation with no choice' => [LeaveApplication::VACATION, null, null, 'Say where the leave will be spent: within the Philippines or abroad.'],
    "vacation with sick leave's choice" => [LeaveApplication::VACATION, LeaveApplication::IN_HOSPITAL, 'Flu', 'Say where the leave will be spent: within the Philippines or abroad.'],
    'sick with no choice' => [LeaveApplication::SICK, '', 'Flu', 'Say whether the sick leave is in hospital or out patient.'],
    'study with no choice' => [LeaveApplication::STUDY, null, null, "Choose the study leave's purpose: completion of a master's degree or a BAR/Board examination review."],
    'abroad without where' => [LeaveApplication::VACATION, LeaveApplication::ABROAD, '   ', 'Specify where abroad the leave will be spent.'],
    'in hospital without the illness' => [LeaveApplication::SICK, LeaveApplication::IN_HOSPITAL, null, 'Specify the illness.'],
    'women without the illness' => [LeaveApplication::WOMEN, null, '', 'Specify the illness.'],
    'too long' => [LeaveApplication::SICK, LeaveApplication::OUT_PATIENT, str_repeat('x', 256), 'Keep the details of leave to 255 characters.'],
]);

it('reads a stored section leniently', function () {
    expect(LeaveApplication::section('SICK'))->toBe(LeaveApplication::SICK)
        ->and(LeaveApplication::section('sick'))->toBe(LeaveApplication::NONE)
        ->and(LeaveApplication::section(null))->toBe(LeaveApplication::NONE);
});

it('describes what was recorded', function () {
    expect(LeaveApplication::describe(LeaveApplication::ABROAD, 'Japan'))->toBe('Abroad: Japan')
        ->and(LeaveApplication::describe(LeaveApplication::WITHIN_PH, null))->toBe('Within the Philippines')
        ->and(LeaveApplication::describe(LeaveApplication::MASTERS, ''))->toBe("Completion of Master's Degree")
        ->and(LeaveApplication::describe(null, 'Japan'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Commutation
|--------------------------------------------------------------------------
*/

it('reads commutation as not requested unless said, and refuses an unknown value', function () {
    expect(LeaveApplication::commutation(null))->toBe(LeaveApplication::NOT_REQUESTED)
        ->and(LeaveApplication::commutation(''))->toBe(LeaveApplication::NOT_REQUESTED)
        ->and(LeaveApplication::commutation('REQUESTED'))->toBe(LeaveApplication::REQUESTED)
        ->and(LeaveApplication::commutation('NOT_REQUESTED'))->toBe(LeaveApplication::NOT_REQUESTED)
        ->and(LeaveApplication::commutation('YES'))->toBeNull();
});
