<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Records;

/** Management maintains employment terms and employee identifiers outside personal record requests. */
final class OwnRecordFields
{
    public const SECTION_REFUSAL = 'Employee Details cannot be changed through a personal record request.';

    public const EMPLOYEE_ID_REFUSAL = 'Employee ID cannot be changed through a personal record request.';

    public static function allowsSection(string $section): bool
    {
        return $section !== 'employment';
    }

    public static function allowsField(string $section, string $field): bool
    {
        return self::allowsSection($section) && ! in_array($field, ['employee_id_no', 'employee_code'], true);
    }
}
