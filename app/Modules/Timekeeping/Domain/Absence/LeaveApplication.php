<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Absence;

/**
 * The employee's part of the leave application that is not dates or pay:
 * the DETAILS OF LEAVE and COMMUTATION (sections 6.B and 6.D of the leave
 * application form).
 *
 * Which details a request asks for is the leave type's SECTION, set by HR
 * on Leave Settings (`leave_types.leave_details`) because the types are
 * HR's own names: Vacation / Special Privilege Leave asks where the leave
 * is spent (within the Philippines, or abroad — specify where); Sick Leave
 * asks in hospital or out patient, with the illness; Special Leave
 * Benefits for Women asks the illness; Study Leave asks completion of a
 * master's degree or a BAR/Board examination review. A type whose section
 * is NONE asks nothing. Where a section asks, ITS CHOICE IS REQUIRED, and
 * so is the text a choice says to specify. Within the Philippines has a
 * line of its own on the form, which the employee may leave blank.
 *
 * Commutation is "Not requested" or "Requested" on every leave. Both are
 * RECORDED ONLY: they change no pay, no credits and no approval.
 *
 * Pure: no clock, database or screen.
 */
final class LeaveApplication
{
    public const string NONE = 'NONE';

    public const string VACATION = 'VACATION';

    public const string SICK = 'SICK';

    public const string WOMEN = 'WOMEN';

    public const string STUDY = 'STUDY';

    public const array SECTIONS = [self::NONE, self::VACATION, self::SICK, self::WOMEN, self::STUDY];

    /** @var array<string, string> the sections, as Leave Settings offers them */
    public const array SECTION_LABELS = [
        self::NONE => 'None',
        self::VACATION => 'Vacation / Special Privilege Leave',
        self::SICK => 'Sick Leave',
        self::WOMEN => 'Special Leave Benefits for Women',
        self::STUDY => 'Study Leave',
    ];

    public const string WITHIN_PH = 'WITHIN_PH';

    public const string ABROAD = 'ABROAD';

    public const string IN_HOSPITAL = 'IN_HOSPITAL';

    public const string OUT_PATIENT = 'OUT_PATIENT';

    public const string ILLNESS = 'ILLNESS';

    public const string MASTERS = 'MASTERS';

    public const string BAR_BOARD = 'BAR_BOARD';

    /** @var array<string, array<string, string>> section => its choices, as the form names them */
    public const array CHOICES = [
        self::VACATION => [self::WITHIN_PH => 'Within the Philippines', self::ABROAD => 'Abroad'],
        self::SICK => [self::IN_HOSPITAL => 'In Hospital', self::OUT_PATIENT => 'Out Patient'],
        self::WOMEN => [self::ILLNESS => 'Illness'],
        self::STUDY => [self::MASTERS => "Completion of Master's Degree", self::BAR_BOARD => 'BAR/Board Examination Review'],
    ];

    /** @var array<string, string> the choices that ask for text => what the box asks */
    public const array SPECIFY = [
        self::ABROAD => 'Specify where',
        self::IN_HOSPITAL => 'Specify illness',
        self::OUT_PATIENT => 'Specify illness',
        self::ILLNESS => 'Specify illness',
    ];

    /** @var array<string, string> the choices whose text may be left blank => what the box asks */
    public const array SPECIFY_OPTIONAL = [self::WITHIN_PH => 'Specify where (optional)'];

    /** The longest text kept: the column's length. */
    public const int NOTE_MAX = 255;

    public const string NOT_REQUESTED = 'NOT_REQUESTED';

    public const string REQUESTED = 'REQUESTED';

    /** @var array<string, string> */
    public const array COMMUTATION_LABELS = [self::NOT_REQUESTED => 'Not requested', self::REQUESTED => 'Requested'];

    private function __construct() {}

    /** A stored section read leniently: anything unknown asks nothing. */
    public static function section(mixed $value): string
    {
        return is_string($value) && in_array($value, self::SECTIONS, true) ? $value : self::NONE;
    }

    /**
     * The choice and text a request keeps for a section, or the refusal:
     * `[choice, note, error]`. A section of NONE keeps nothing whatever was
     * sent; Special Leave Benefits for Women has its one choice; a choice
     * that asks no text keeps none; a choice whose text is optional keeps
     * what was typed, or none.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    public static function details(string $section, ?string $choice, ?string $note): array
    {
        $choices = self::CHOICES[$section] ?? [];
        if ($choices === []) {
            return [null, null, null];
        }
        if (count($choices) === 1) {
            $choice = array_key_first($choices);
        }
        $choice = trim((string) $choice);
        if (! isset($choices[$choice])) {
            return [null, null, match ($section) {
                self::VACATION => 'Say where the leave will be spent: within the Philippines or abroad.',
                self::SICK => 'Say whether the sick leave is in hospital or out patient.',
                default => "Choose the study leave's purpose: completion of a master's degree or a BAR/Board examination review.",
            }];
        }
        $optional = isset(self::SPECIFY_OPTIONAL[$choice]);
        if (! isset(self::SPECIFY[$choice]) && ! $optional) {
            return [$choice, null, null];
        }
        $note = trim((string) $note);
        if ($note === '' && $optional) {
            return [$choice, null, null];
        }
        if ($note === '') {
            return [null, null, $choice === self::ABROAD ? 'Specify where abroad the leave will be spent.' : 'Specify the illness.'];
        }
        if (mb_strlen($note) > self::NOTE_MAX) {
            return [null, null, 'Keep the details of leave to '.self::NOTE_MAX.' characters.'];
        }

        return [$choice, $note, null];
    }

    /** The stored commutation for what was sent: not requested when nothing was, null when the value is unknown. */
    public static function commutation(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return self::NOT_REQUESTED;
        }

        return isset(self::COMMUTATION_LABELS[$value]) ? $value : null;
    }

    /** "Abroad: Japan", "In Hospital: Dengue", "Within the Philippines"; null when nothing was recorded. */
    public static function describe(?string $choice, ?string $note): ?string
    {
        if ($choice === null || $choice === '') {
            return null;
        }
        $label = null;
        foreach (self::CHOICES as $choices) {
            $label ??= $choices[$choice] ?? null;
        }
        $label ??= $choice;
        $note = trim((string) $note);

        return $note === '' ? $label : $label.': '.$note;
    }
}
