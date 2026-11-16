<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Domain\Projects;

use Illuminate\Validation\ValidationException;

final class PlanningHours
{
    public static function parse(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d{0,3}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages(['hours' => 'Enter up to two decimal places, or leave blank to clear.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $hours = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($hours > 16800) {
            throw ValidationException::withMessages(['hours' => 'A week has at most 168 hours.']);
        }

        return $hours;
    }

    public static function format(int $hundredths): string
    {
        return rtrim(rtrim(intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT), '0'), '.');
    }
}
