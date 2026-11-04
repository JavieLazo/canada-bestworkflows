<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Signatories;

/**
 * The signature slots a payroll document carries, in print order: the three
 * the register and the payslip are signed with before they leave Finance.
 * The slots are fixed; only the name and designation in each are data
 * (`payroll_signatories`). A slot's label is what the document prints above
 * the line.
 */
final class SignatorySlot
{
    public const string PREPARED = 'PREPARED';

    public const string CHECKED = 'CHECKED';

    public const string CERTIFIED = 'CERTIFIED';

    /** @var array<string, string> slot => the label printed above the signature line */
    private const array LABELS = [
        self::PREPARED => 'Prepared by',
        self::CHECKED => 'Checked by',
        self::CERTIFIED => 'Certified correct by',
    ];

    /** @return list<string> */
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
}
