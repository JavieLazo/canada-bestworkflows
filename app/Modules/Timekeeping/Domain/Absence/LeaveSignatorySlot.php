<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Absence;

/**
 * The officers named on the Application for Leave who are the same for every
 * application: who certifies the leave credits and who approves. The head of
 * office differs by office and belongs to the department or unit instead.
 * No officer is printed ahead of that choice: a slot reads as a blank line
 * until HR sets it.
 *
 * Pure: no clock, database or screen.
 */
final class LeaveSignatorySlot
{
    public const string CERTIFIED = 'CERTIFIED';

    public const string APPROVED = 'APPROVED';

    /** @var array<string, string> */
    private const array LABELS = [self::CERTIFIED => 'Certified by', self::APPROVED => 'Approved by'];

    private function __construct() {}

    /** @return list<string> in the form's order */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isSlot(string $slot): bool
    {
        return isset(self::LABELS[$slot]);
    }

    public static function label(string $slot): string
    {
        return self::LABELS[$slot] ?? $slot;
    }

    /** @return array{0: string, 1: string} the name and designation shown for a slot nobody was picked for: none */
    public static function printed(string $slot): array
    {
        return ['', ''];
    }
}
