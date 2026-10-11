<?php

declare(strict_types=1);

namespace App\Platform\Reports;

use App\Support\ManilaClock;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validated report filters are shared by the preview, printed document and CSV. */
final readonly class ReportCriteria
{
    // Include the full 201-person workforce while bounding explicit download URL filters.
    public const int MAX_EMPLOYEES = 250;

    /**
     * @param  list<string>  $employeeIds
     * @param  list<string>  $deductionCodes
     * @param  array<string, string>  $filters
     * @param  list<string>  $columns
     */
    private function __construct(
        public string $type,
        public string $from,
        public string $to,
        public array $employeeIds,
        public array $deductionCodes,
        public array $filters,
        public array $columns,
        public string $sort,
        public string $direction,
        public string $view,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        if (($input['type'] ?? '') === 'employee-list' && ($input['view'] ?? '') !== 'analysis') {
            // Employee directories have no period; hidden draft dates must
            // not prevent generation. Analysis keeps its activity window.
            $input['from'] = $input['to'] = ManilaClock::todayKey();
        }
        if (array_key_exists('employeeIds', $input)) {
            $employeeIds = $input['employeeIds'];
        } elseif (array_key_exists('employees', $input)) {
            // URL filters are a single comma-separated value; an array here
            // must fail validation rather than silently widening the report.
            $employeeIds = is_string($input['employees'])
                ? ($input['employees'] === '' ? [] : explode(',', $input['employees']))
                : null;
        } else {
            // A single-employee link (`employeeId` or `employee`) selects that
            // one employee, so links issued in that form keep working.
            $legacy = $input['employeeId'] ?? $input['employee'] ?? '';
            $employeeIds = $legacy === '' ? [] : [$legacy];
        }
        $codes = $input['deductionCodes'] ?? explode(',', is_string($input['deductions'] ?? null) ? $input['deductions'] : '');
        $filters = array_key_exists('filters', $input) ? $input['filters'] : [];
        if (! array_key_exists('filters', $input)) {
            foreach ($input as $key => $value) {
                if (str_starts_with((string) $key, 'f_')) {
                    $filters[substr((string) $key, 2)] = $value;
                }
            }
        }
        $columns = array_key_exists('columns', $input) ? $input['columns'] : [];
        if (is_string($columns)) {
            $columns = $columns === '' ? [] : explode(',', $columns);
        }
        $values = Validator::make([
            'type' => $input['type'] ?? '',
            'from' => $input['from'] ?? '',
            'to' => $input['to'] ?? '',
            'employeeIds' => $employeeIds,
            'deductionCodes' => is_array($codes) ? array_values(array_filter($codes, static fn (mixed $code): bool => $code !== '')) : $codes,
            'filters' => $filters,
            'columns' => $columns,
            'sort' => array_key_exists('sort', $input) ? $input['sort'] : '',
            'direction' => array_key_exists('direction', $input) ? $input['direction'] : 'asc',
            'view' => array_key_exists('view', $input) ? $input['view'] : 'details',
        ], [
            'type' => ['required', 'string', 'max:50'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employeeIds' => ['array', 'list', 'max:'.self::MAX_EMPLOYEES],
            'employeeIds.*' => ['required', 'string', 'max:26', 'distinct', 'regex:/\A[^,\s]+\z/'],
            'deductionCodes' => ['array', 'max:100'],
            'deductionCodes.*' => ['required', 'string', 'max:100', 'distinct'],
            'filters' => ['array', 'max:30'],
            'filters.*' => ['present', 'string', 'max:200'],
            'columns' => ['array', 'list', 'max:30'],
            'columns.*' => ['required', 'string', 'max:50', 'distinct', 'regex:/\A[a-z_]+\z/'],
            'sort' => ['present', 'string', 'max:50'],
            'direction' => ['required', 'in:asc,desc'],
            'view' => ['required', 'in:details,summary,analysis'],
        ])->validate();

        if (ManilaClock::instant($values['from'])->diffInDays(ManilaClock::instant($values['to'])) > 365) {
            throw ValidationException::withMessages(['to' => 'Choose a period of at most 366 days.']);
        }

        foreach (array_keys($values['filters']) as $key) {
            if (! is_string($key) || preg_match('/\A[a-zA-Z][a-zA-Z0-9]*\z/', $key) !== 1) {
                throw ValidationException::withMessages(['filters' => 'Choose valid report filters.']);
            }
        }
        // Match HTTP trimming before previewing, so a blank draft and its
        // download URL cannot select different coverage.
        $values['filters'] = array_map(static fn (string $value): string => trim($value), $values['filters']);

        return new self($values['type'], $values['from'], $values['to'], $values['employeeIds'], $values['deductionCodes'], $values['filters'], $values['columns'], $values['sort'], $values['direction'], $values['view']);
    }

    /** @return array<string, string> */
    public function query(): array
    {
        return array_filter([
            'type' => $this->type, 'from' => $this->from, 'to' => $this->to,
            'employees' => implode(',', $this->employeeIds), 'deductions' => implode(',', $this->deductionCodes),
            'columns' => implode(',', $this->columns), 'sort' => $this->sort,
            'direction' => $this->direction, 'view' => $this->view,
            ...array_combine(array_map(static fn (string $key): string => 'f_'.$key, array_keys($this->filters)), array_values($this->filters)),
        ], static fn (string $value): bool => $value !== '');
    }
}
