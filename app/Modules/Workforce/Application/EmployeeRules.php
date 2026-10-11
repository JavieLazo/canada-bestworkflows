<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Modules\Organization\Contracts\OrganizationReferences;
use Carbon\CarbonImmutable;

/** The hire and update policies, shared by every employee write surface. */
final class EmployeeRules
{
    /** @param array<string, mixed> $payload */
    public static function creating(array $payload): ?string
    {
        foreach (EmployeeFields::TEXT_CAPS as $key => $cap) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && mb_strlen($value) > $cap) {
                return "{$key}: must be at most {$cap} characters.";
            }
        }

        foreach (EmployeeFields::NUMERIC_CAPS as $key => $cap) {
            if (is_float($payload[$key] ?? null) && $payload[$key] > $cap) {
                return "{$key}: must be at most {$cap}.";
            }
        }

        $dateError = EmployeeFields::employeeDateError([
            'birth_date' => $payload['birth_date'],
            'date_hired' => (string) $payload['date_hired'],
            'date_regular' => $payload['date_regular'],
            'date_resigned' => $payload['date_resigned'],
        ], EmployeeFields::todayKey());

        if ($dateError !== null) {
            return $dateError;
        }

        $duplicate = self::duplicateIdNo($payload['employee_id_no'] ?? null, null);

        if ($duplicate !== null) {
            return $duplicate;
        }

        if ($payload['work_site_id'] === null
            || ! app(OrganizationReferences::class)->siteExists((string) $payload['work_site_id'])) {
            return 'Choose an existing work site for the employee.';
        }
        // A new record is a new assignment: an inactive site is withheld.
        if (! app(OrganizationReferences::class)->siteAssignable((string) $payload['work_site_id'])) {
            return 'That work site is inactive, so it cannot be chosen for a new assignment. Choose an active site, or make it active again in Site Settings.';
        }

        // A hire is a new link: the position must be on the list.
        $position = EmployeePosition::error(null, self::positionChoice($payload));

        if ($position !== null) {
            return $position;
        }

        if ($payload['employment_status'] === null
            || EmploymentStatusOption::query()->where('name', $payload['employment_status'])->doesntExist()) {
            return 'Choose an employment status from Records Management.';
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    public static function updating(EmployeeProfile $employee, array $payload): ?string
    {
        foreach (EmployeeFields::TEXT_CAPS as $key => $cap) {
            if (isset($payload[$key]) && is_string($payload[$key]) && mb_strlen($payload[$key]) > $cap) {
                return "{$key}: must be at most {$cap} characters.";
            }
        }

        foreach (EmployeeFields::NUMERIC_CAPS as $key => $cap) {
            if (isset($payload[$key]) && is_float($payload[$key]) && $payload[$key] > $cap) {
                return "{$key}: must be at most {$cap}.";
            }
        }

        $day = static fn (?CarbonImmutable $d): ?string => $d?->format('Y-m-d');

        $merged = [
            'birth_date' => array_key_exists('birth_date', $payload)
                ? $payload['birth_date']
                : $day($employee->birth_date),
            'date_hired' => array_key_exists('date_hired', $payload) && $payload['date_hired'] !== null
                ? (string) $payload['date_hired']
                : (string) $day($employee->date_hired),
            'date_regular' => array_key_exists('date_regular', $payload)
                ? $payload['date_regular']
                : $day($employee->date_regular),
            'date_resigned' => array_key_exists('date_resigned', $payload)
                ? $payload['date_resigned']
                : $day($employee->date_resigned),
        ];

        $dateError = EmployeeFields::employeeDateError($merged, EmployeeFields::todayKey());

        if ($dateError !== null) {
            return $dateError;
        }

        if (array_key_exists('employee_id_no', $payload)) {
            $duplicate = self::duplicateIdNo(
                is_string($payload['employee_id_no']) ? $payload['employee_id_no'] : null,
                $employee->id,
            );

            if ($duplicate !== null) {
                return $duplicate;
            }
        }

        if (isset($payload['work_site_id']) && is_string($payload['work_site_id'])
            && ! app(OrganizationReferences::class)->siteExists((string) $payload['work_site_id'])) {
            return 'Choose an existing work site for the employee.';
        }
        // Keeping the site the record already names is not a new assignment,
        // so an employee at a since-retired site still saves.
        if (isset($payload['work_site_id']) && is_string($payload['work_site_id'])
            && ! app(OrganizationReferences::class)->siteAssignable($payload['work_site_id'], $employee->work_site_id)) {
            return 'That work site is inactive, so it cannot be chosen for a new assignment. Choose an active site, or make it active again in Site Settings.';
        }

        // Keeping the position the record already holds is not a new link,
        // so a title that is not on the Position list still saves (KEEP);
        // blank clears it.
        if (array_key_exists(EmployeePosition::FORM_KEY, $payload)) {
            $position = EmployeePosition::error($employee, self::positionChoice($payload));

            if ($position !== null) {
                return $position;
            }
        }

        if (isset($payload['employment_status']) && is_string($payload['employment_status'])
            && EmploymentStatusOption::query()->where('name', $payload['employment_status'])->doesntExist()) {
            return 'Choose an employment status from Records Management.';
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private static function positionChoice(array $payload): ?string
    {
        $choice = $payload[EmployeePosition::FORM_KEY] ?? null;

        return is_string($choice) && $choice !== '' ? $choice : null;
    }

    /**
     * The message for a hand-typed Employee ID that another record already
     * holds. `emp_employee_id_no_unq` is the real guarantee — this exists so
     * the operator is told WHICH value collided instead of meeting the generic
     * save failure, because CreateEmployee retries a QueryException once before
     * giving up and cannot tell a duplicate id from a code-mint race.
     *
     * Blank is never a duplicate: it is stored as NULL, and a unique index
     * counts NULLs as distinct on every engine this runs on.
     *
     * The comparison is the database's, so it matches the index exactly:
     * case- and accent-insensitive on MariaDB (utf8mb4_unicode_ci), case-
     * sensitive on SQLite (BINARY). A case-only near-duplicate therefore fails
     * here on the host and at the index in a test — never silently passes both.
     */
    private static function duplicateIdNo(?string $value, ?string $exceptId): ?string
    {
        $value = $value === null ? '' : trim($value);

        if ($value === '') {
            return null;
        }

        $query = EmployeeProfile::query()->where('employee_id_no', $value);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists()
            ? "Employee ID {$value} is already on another employee's record."
            : null;
    }
}
