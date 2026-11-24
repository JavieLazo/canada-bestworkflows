<?php

declare(strict_types=1);

namespace App\Livewire\Employees\Support;

use App\Livewire\Employees\EmployeeFields;
use App\Support\DateText;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;

/** The change view and its CSV report use the same human-readable values. */
final class EmploymentChangeDisplay
{
    /**
     * The one-line title of a saved edit: the fields it changed, in the order
     * the comparison lists them.
     *
     * @param  list<array{label: string, ...}>  $changes
     */
    public static function fieldList(array $changes): string
    {
        return implode(', ', array_column($changes, 'label'));
    }

    /** When a record was saved, on the local clock; the day alone when the hour says nothing. */
    public static function savedAt(CarbonImmutable $at, bool $withTime = true): string
    {
        return $at->setTimezone(LocalClock::zone())->format($withTime ? 'd M Y, g:i A' : 'd M Y');
    }

    public static function value(string $field, ?string $value, bool $known): string
    {
        if (! $known) {
            return 'Not recorded';
        }

        if ($value === null || $value === '') {
            return 'Not set';
        }

        return match ($field) {
            'monthly_rate', 'daily_rate' => EmployeeFields::money((float) $value),
            'date_hired', 'date_regular', 'date_resigned' => DateText::toDisplay($value),
            'is_active' => $value === '1' ? 'Active' : 'Inactive',
            'employment_status' => EmployeeFields::employmentStatusLabel($value),
            default => $value,
        };
    }
}
