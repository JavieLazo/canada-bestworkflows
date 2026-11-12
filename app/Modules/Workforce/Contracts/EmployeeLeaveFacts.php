<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

/**
 * The employment facts leave reads, for every employee or for some: when
 * they joined, when they left, whether the record is active, and who may use
 * a leave type restricted by employment status or sex — the status their
 * record holds today (by name, and the Employment list id that name
 * matches, ignoring case and surrounding spaces as payroll's status rules
 * read it), their sex, their status over time, the name and position of
 * whoever signs the Application for Leave, and what that form states about
 * its applicant. A system read with no actor — the earning job runs from the
 * schedule, and every employee's form names its signatories — so it exposes
 * nothing beyond those facts.
 */
interface EmployeeLeaveFacts
{
    /**
     * @param  list<string>|null  $ids  null for every employee record
     * @return array<string, array{id: string, date_hired: string, date_resigned: ?string, is_active: bool, employment_status: ?string, employment_status_id: ?string, sex: ?string}> keyed by employee id
     */
    public function forPosting(?array $ids = null): array;

    /**
     * Each employee's employment status over time, oldest first: the first
     * status Employment History recorded, from the date hired, then each
     * row that changed the status from its effective date, ending with the
     * status the record holds today. An employee with no history has the
     * record's status from the date hired. A record whose status differs from
     * the last one recorded changed after that row, on a day not known: it
     * runs from the last row's effective date, the earliest it can have.
     * History rows keep the status NAME they were written with and are never
     * rewritten, so after an Employment list entry is renamed its older rows
     * name no list entry (`status_id` null) and read as not eligible for a
     * type restricted by status.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{from: string, status_id: ?string}>> keyed by employee id
     */
    public function statusTimelines(array $ids): array;

    /**
     * The people the Application for Leave names as its signatories, as
     * their records stand: the full name, the position ('' when the record
     * has none) and whether the record is active.
     *
     * @param  list<string>  $ids
     * @return array<string, array{name: string, position: string, is_active: bool}> keyed by employee id
     */
    public function signatories(array $ids): array;

    /**
     * What the Application for Leave states about its applicant, stored when
     * the leave is approved: the name, the position, the placement and its
     * head of office, and the rates. The approver need not be allowed to read
     * the rates, so whoever SHOWS this must apply the record's own rule.
     *
     * @return array{first_name: string, middle_name: string|null, last_name: string, suffix: string|null, position: string|null, department_name: string|null, unit_name: string|null, monthly_rate: float, daily_rate: float, head: array{name: string, designation: string}|null}|null
     */
    public function applicant(string $employeeId): ?array;
}
