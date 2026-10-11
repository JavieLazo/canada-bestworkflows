<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeEligibility;
use App\Models\EmployeeProfile;
use App\Models\EmployeeTraining;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Workforce\Application\EmployeeAnalyticsDocument;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\EmployeePlacements;
use App\Modules\Workforce\Application\PdsLists;
use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;
use App\Support\CodeSort;
use App\Support\EmployeeIdentity;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Scoped employee reports answer workforce and profile questions without reading pay or private declarations. */
final class EmployeeReport
{
    /** @var list<string> */
    private const array FILTERS = ['search', 'active', 'employment', 'department', 'position', 'worksite', 'sex', 'civilStatus', 'education', 'missing', 'ageMin', 'ageMax', 'tenureMin', 'tenureMax', 'hiredFrom', 'hiredTo', 'analysis', 'groupBy', 'crossTab'];

    /** @var array<string, string> */
    private const array MISSING = [
        'employee_id' => 'Employee ID', 'position' => 'Position', 'department' => 'Department',
        'worksite' => 'Worksite', 'email' => 'Email', 'mobile' => 'Mobile number',
        'birth_date' => 'Birth date', 'sex' => 'Sex', 'civil_status' => 'Civil status', 'education' => 'Education',
    ];

    /** @return array<string, string> */
    public static function columns(): array
    {
        return [
            'employee_code' => 'ID', 'employee_id' => 'Employee ID', 'employee' => 'Employee',
            'position' => 'Position', 'employment' => 'Employment status', 'active' => 'Active status',
            'date_hired' => 'Date hired', 'worksite' => 'Worksite', 'department' => 'Department', 'unit' => 'Unit',
            'email' => 'Email', 'mobile' => 'Mobile number', 'birth_date' => 'Birth date', 'age' => 'Age (years)',
            'sex' => 'Sex', 'civil_status' => 'Civil status', 'tenure' => 'Tenure (years)',
            'education' => 'Highest recorded education', 'missing' => 'Missing information',
        ];
    }

    /** @return array<string, string> */
    public static function analysisTopics(): array
    {
        return EmployeeAnalyticsDocument::topics();
    }

    /** @return array<string, string> */
    public static function groupings(): array
    {
        return EmployeeAnalyticsDocument::groupings();
    }

    /** @return array<string, string> */
    public static function crossTabs(): array
    {
        return EmployeeAnalyticsDocument::crossTabs();
    }

    /** @return list<string> */
    public static function defaults(): array
    {
        return ['employee_id', 'employee', 'position', 'employment', 'active', 'date_hired', 'worksite'];
    }

    /** Options derive only from records visible to this source grant, including inactive records.
     * @return array<string, array<string, string>>
     */
    public function options(Access $actor): array
    {
        $this->authorize($actor);
        $fields = ['id', 'employment_status', 'position', 'work_site_id', 'sex', 'civil_status'];
        if (EmployeePlacements::ready()) {
            $fields[] = 'position_id';
        }
        $employees = $this->scoped($actor)->limit(ReportDocument::MAX_ROWS + 1)->get(array_values(array_unique($fields)));
        ReportDocument::guardSize($employees->count());
        $placements = EmployeePlacements::of($employees);
        $sites = $employees->isEmpty() ? collect() : app(OrganizationDirectory::class)->sites($actor)->keyBy('id');
        $options = [
            'active' => ['ALL' => 'Any active status', 'ACTIVE' => 'Active', 'INACTIVE' => 'Inactive'],
            'employment' => ['ALL' => 'Any employment status'],
            'department' => ['ALL' => 'Any department or unit', 'NONE' => 'No department'],
            'position' => ['ALL' => 'Any position', 'NONE' => 'No position'],
            'worksite' => ['ALL' => 'Any worksite', 'NONE' => 'No worksite'],
            'sex' => ['ALL' => 'Any sex', 'NONE' => 'Not recorded'],
            'civilStatus' => ['ALL' => 'Any civil status', 'NONE' => 'Not recorded'],
            'education' => ['ALL' => 'Any education', 'NONE' => 'No education recorded', ...EmployeeFields::EDUCATION_LEVELS],
            'missing' => ['ALL' => 'Any completeness', 'ANY' => 'Any missing information', ...self::MISSING],
        ];
        $observed = [];
        foreach ($employees as $employee) {
            foreach (['employment' => 'employment_status', 'position' => 'position', 'sex' => 'sex', 'civilStatus' => 'civil_status'] as $filter => $field) {
                $value = trim((string) $employee->getAttribute($field));
                if ($value !== '') {
                    $observed[$filter][$value] = $filter === 'employment' ? HrisFormat::employmentStatus($value) : $value;
                }
            }
            $site = $sites->get($employee->work_site_id);
            if ($site !== null) {
                $observed['worksite'][$site->id] = $site->name;
            }
            $placement = $placements[$employee->id] ?? [];
            foreach (['department', 'unit'] as $kind) {
                if (is_string($placement[$kind.'_id'] ?? null) && is_string($placement[$kind.'_name'] ?? null)) {
                    $observed['department'][$placement[$kind.'_id']] = ($kind === 'unit' ? 'Unit: ' : '').$placement[$kind.'_name'];
                }
            }
        }
        foreach ($observed as $filter => $choices) {
            asort($choices, SORT_NATURAL | SORT_FLAG_CASE);
            $options[$filter] += $choices;
        }

        return $options;
    }

    public function generate(Access $actor, ReportCriteria $criteria): ReportDocument
    {
        $this->authorize($actor);
        abort_unless($criteria->type === 'employee-list', 404);
        $this->validate($criteria);
        $query = $this->scoped($actor);
        if ($criteria->employeeIds !== []) {
            $query->whereIn('id', $criteria->employeeIds);
        }
        $this->filterQuery($query, $actor, $criteria->filters);
        $keys = $criteria->columns === [] ? self::defaults() : $criteria->columns;
        $needed = $this->neededFields($criteria, $keys);
        $fields = ['id', 'employee_code', 'employee_id_no', 'first_name', 'last_name', 'suffix', 'position', 'employment_status', 'is_active', 'date_hired', 'work_site_id'];
        foreach (['email' => ['email'], 'mobile' => ['phone'], 'birth_date' => ['birth_date'], 'sex' => ['sex'], 'civil_status' => ['civil_status'], 'tenure' => ['date_resigned'], 'service_dates' => ['date_regular', 'date_resigned'], 'education' => ['college', 'college_course', 'college_year_graduated', 'graduate_school', 'graduate_course', 'graduate_year_graduated']] as $key => $sourceFields) {
            if (isset($needed[$key])) {
                $fields = [...$fields, ...$sourceFields];
            }
        }
        if (EmployeePlacements::ready()) {
            $fields[] = 'position_id';
        }
        if (isset($needed['education']) && PdsLists::ready('education')) {
            $query->with('educations:id,employee_id,level,school,course,attended_from,attended_to,year_graduated,units_earned,honors');
        }
        $employees = $query->orderBy('last_name')->orderBy('first_name')->orderBy('suffix')->orderBy('id')
            ->limit(ReportDocument::MAX_ROWS + 1)->get(array_values(array_unique($fields)));
        ReportDocument::guardSize($employees->count());
        $placements = EmployeePlacements::of($employees);
        $sites = $employees->isEmpty() ? collect() : app(OrganizationDirectory::class)->sites($actor)->keyBy('id');
        $today = ManilaClock::todayKey();
        $facts = [];
        foreach ($employees as $employee) {
            $placement = $placements[$employee->id] ?? [];
            [$education, $educationLevel] = isset($needed['education']) ? $this->education($employee) : ['', 'NONE'];
            $birth = isset($needed['birth_date']) ? ($employee->birth_date?->format('Y-m-d') ?? '') : '';
            $hired = $employee->date_hired->format('Y-m-d');
            $until = isset($needed['tenure']) ? min($today, $employee->date_resigned?->format('Y-m-d') ?? $today) : $today;
            $fact = [
                'id' => $employee->id, 'employee_code' => EmployeeIdentity::index($employee, ''), 'employee_id' => EmployeeIdentity::number($employee, ''),
                'employee' => EmployeeName::display($employee), 'position' => trim($employee->position ?? ''),
                'employment' => HrisFormat::employmentStatus($employee->employment_status), 'active' => $employee->is_active ? 'Active' : 'Inactive',
                'date_hired' => $hired,
                'date_regular' => isset($needed['service_dates']) ? ($employee->date_regular?->format('Y-m-d') ?? '') : '',
                'date_resigned' => isset($needed['service_dates']) ? ($employee->date_resigned?->format('Y-m-d') ?? '') : '',
                'worksite' => $sites->get($employee->work_site_id)->name ?? '',
                'department' => (string) ($placement['department_name'] ?? ''), 'unit' => (string) ($placement['unit_name'] ?? ''),
                'email' => isset($needed['email']) ? trim($employee->email ?? '') : '',
                'mobile' => isset($needed['mobile']) ? trim($employee->phone ?? '') : '',
                'birth_date' => $birth, 'age' => $this->years($birth, $today),
                'sex' => isset($needed['sex']) ? trim($employee->sex ?? '') : '',
                'civil_status' => isset($needed['civil_status']) ? trim($employee->civil_status ?? '') : '',
                'tenure' => $this->years($hired, $until),
                'education' => $education, 'education_level' => $educationLevel,
                'tie' => [$employee->last_name, $employee->first_name, $employee->suffix ?? '', $employee->id],
            ];
            $missing = array_keys(array_filter(self::MISSING, static fn (string $label, string $key): bool => $fact[$key] === '', ARRAY_FILTER_USE_BOTH));
            $fact['missing'] = implode(', ', array_map(static fn (string $key): string => self::MISSING[$key], $missing));
            if ($this->matches($fact, $missing, $criteria->filters)) {
                $facts[] = $fact;
            }
        }
        $sort = $criteria->sort === '' ? 'employee' : $criteria->sort;
        usort($facts, static function (array $left, array $right) use ($sort, $criteria): int {
            $a = $left[$sort];
            $b = $right[$sort];
            $blankA = $a === null || $a === '';
            $blankB = $b === null || $b === '';
            if ($blankA !== $blankB) {
                return $blankA ? 1 : -1;
            }
            $order = match ($sort) {
                'employee' => $left['tie'] <=> $right['tie'],
                'employee_code' => CodeSort::compare((string) $a, (string) $b),
                'age', 'tenure' => $a <=> $b,
                default => strnatcasecmp((string) $a, (string) $b),
            };

            return ($criteria->direction === 'desc' ? -$order : $order) ?: ($left['tie'] <=> $right['tie']);
        });
        if ($criteria->view === 'analysis') {
            [$trainings, $eligibilities] = $this->developmentFacts($criteria, $facts);

            return EmployeeAnalyticsDocument::build($criteria, $facts, $trainings, $eligibilities, $this->coverage($criteria, $sort, $facts));
        }
        $columns = self::columns();
        $rows = array_map(static fn (array $fact): array => array_map(
            static fn (string $key): string => $key === 'employee_id'
                ? EmployeeIdentity::number(['employee_id_no' => $fact[$key]]) : (string) $fact[$key],
            $keys,
        ), $facts);
        $numeric = array_values(array_filter(array_keys($keys), static fn (int $index): bool => in_array($keys[$index], ['age', 'tenure'], true)));

        return new ReportDocument('Employee List', array_map(static fn (string $key): string => $columns[$key], $keys), $rows, [
            'Current employee records; the reporting period does not reconstruct historical employment details.',
            $this->coverage($criteria, $sort, $facts),
            'Age and tenure are completed calendar years as of '.$today.'. Tenure ends on a recorded resignation date when earlier.',
            'Education shows the highest recorded level, whether completed or attended; stored education rows take precedence over legacy details at that level.',
            'Missing information means blank employee ID, position, department, worksite, email, mobile number, birth date, sex, civil status or education; an optional unit is not required.',
        ], [], [
            ['label' => 'Employees', 'value' => (string) count($facts)],
            ['label' => 'Active', 'value' => (string) count(array_filter($facts, static fn (array $fact): bool => $fact['active'] === 'Active'))],
            ['label' => 'Inactive', 'value' => (string) count(array_filter($facts, static fn (array $fact): bool => $fact['active'] === 'Inactive'))],
            ['label' => 'Positions represented', 'value' => (string) count(array_unique(array_filter(array_column($facts, 'position'))))],
        ], [], $numeric, $keys);
    }

    /** Read profile details only when a displayed value, filter or ordering requires them.
     * @param  list<string>  $keys
     * @return array<string, true>
     */
    private function neededFields(ReportCriteria $criteria, array $keys): array
    {
        $needed = array_fill_keys([...$keys, $criteria->sort], true);
        if ($criteria->view === 'analysis') {
            $topic = $criteria->filters['analysis'] ?? 'composition';
            $needed[$criteria->filters['groupBy'] ?? 'department'] = true;
            $needed[$criteria->filters['crossTab'] ?? 'sex'] = true;
            $topicFields = match ($topic) {
                'composition' => ['age', 'tenure'],
                'demographics' => ['age', 'sex', 'civil_status', 'tenure'],
                'development' => ['education'],
                'service' => ['tenure', 'service_dates'],
                default => ['missing', 'service_dates'],
            };
            $needed += array_fill_keys($topicFields, true);
        }
        foreach (['age', 'tenure'] as $key) {
            if (($criteria->filters[$key.'Min'] ?? '') !== '' || ($criteria->filters[$key.'Max'] ?? '') !== '') {
                $needed[$key] = true;
            }
        }
        if (! in_array($criteria->filters['education'] ?? 'ALL', ['', 'ALL'], true)) {
            $needed['education'] = true;
        }
        $missing = $criteria->filters['missing'] ?? 'ALL';
        if (isset($needed['missing']) || $missing === 'ANY') {
            $needed += array_fill_keys(array_keys(self::MISSING), true);
        } elseif (! in_array($missing, ['', 'ALL'], true)) {
            $needed[$missing] = true;
        }
        if (isset($needed['age'])) {
            $needed['birth_date'] = true;
        }

        return $needed;
    }

    /** The printed evidence names every applied filter using only labels from matching, permitted employees.
     * @param  list<array<string, mixed>>  $facts
     */
    private function coverage(ReportCriteria $criteria, string $sort, array $facts): string
    {
        $labels = [
            'search' => 'Search', 'active' => 'Active status', 'employment' => 'Employment status',
            'department' => 'Department or unit', 'position' => 'Position', 'worksite' => 'Worksite',
            'sex' => 'Sex', 'civilStatus' => 'Civil status', 'education' => 'Highest recorded education',
            'missing' => 'Missing information', 'ageMin' => 'Minimum age', 'ageMax' => 'Maximum age',
            'tenureMin' => 'Minimum tenure', 'tenureMax' => 'Maximum tenure',
            'hiredFrom' => 'Hired from', 'hiredTo' => 'Hired through',
        ];
        $parts = [];
        foreach ($criteria->filters as $key => $value) {
            if ($value === '' || $value === 'ALL' || in_array($key, ['analysis', 'groupBy', 'crossTab'], true)) {
                continue;
            }
            $display = match ($key) {
                'education' => EmployeeFields::EDUCATION_LEVELS[$value] ?? 'Not recorded',
                'missing' => self::MISSING[$value] ?? 'Any missing information',
                'active' => $value === 'ACTIVE' ? 'Active' : 'Inactive',
                'employment' => HrisFormat::employmentStatus($value),
                'department' => $value === 'NONE' ? 'Not recorded' : (implode(', ', array_unique(array_filter(array_merge(array_column($facts, 'department'), array_column($facts, 'unit'))))) ?: 'Selected placement (no matching employees)'),
                'worksite' => $value === 'NONE' ? 'Not recorded' : ($facts[0]['worksite'] ?? 'Selected worksite (no matching employees)'),
                default => $value === 'NONE' ? 'Not recorded' : $value,
            };
            $parts[] = $labels[$key].': '.$display;
        }
        $selection = $criteria->employeeIds === [] ? 'All permitted employees' : count($criteria->employeeIds).' selected employee(s)';

        $ordering = $criteria->view === 'analysis'
            ? ' Grouped by '.self::groupings()[$criteria->filters['groupBy'] ?? 'department'].', compared by '.self::crossTabs()[$criteria->filters['crossTab'] ?? 'sex'].'.'
            : ' Ordered by '.self::columns()[$sort].' ('.$criteria->direction.').';

        return $selection.($parts === [] ? '; no additional filters' : '; '.implode('; ', $parts)).'.'.$ordering;
    }

    /** Read professional records in two bounded batches, only for the already-filtered cohort.
     * @param  list<array<string, mixed>>  $facts
     * @return array{list<array{employee_id: string, date_to: string, hours: ?float, type: string}>, list<array{employee_id: string, name: string, expires: ?string}>}
     */
    private function developmentFacts(ReportCriteria $criteria, array $facts): array
    {
        if (($criteria->filters['analysis'] ?? 'composition') !== 'development' || $facts === []) {
            return [[], []];
        }
        $ids = array_column($facts, 'id');
        $completionCutoff = min($criteria->to, ManilaClock::todayKey());
        $trainings = PdsLists::ready('training') ? EmployeeTraining::query()->whereIn('employee_id', $ids)
            ->whereDate('date_to', '>=', $criteria->from)->whereDate('date_to', '<=', $completionCutoff)
            ->orderBy('id')->limit(ReportDocument::MAX_ROWS + 1)
            ->get(['employee_id', 'date_to', 'hours', 'ld_type']) : collect();
        ReportDocument::guardSize(count($facts) + $trainings->count());
        $eligibilities = PdsLists::ready('eligibility') ? EmployeeEligibility::query()->whereIn('employee_id', $ids)
            ->orderBy('id')->limit(ReportDocument::MAX_ROWS + 1)
            ->get(['employee_id', 'name', 'license_valid_until']) : collect();
        ReportDocument::guardSize(count($facts) + $trainings->count() + $eligibilities->count());

        return [
            array_values($trainings->map(static fn (EmployeeTraining $row): array => [
                'employee_id' => $row->employee_id, 'date_to' => $row->date_to->format('Y-m-d'),
                'hours' => $row->hours, 'type' => trim($row->ld_type ?? ''),
            ])->all()),
            array_values($eligibilities->map(static fn (EmployeeEligibility $row): array => [
                'employee_id' => $row->employee_id, 'name' => trim($row->name),
                'expires' => $row->license_valid_until?->format('Y-m-d'),
            ])->all()),
        ];
    }

    private function authorize(Access $actor): void
    {
        abort_unless($actor->hasModule(ModuleCode::REPORTS) && $actor->hasModule(ModuleCode::EMPLOYEES), 403);
    }

    /** @return Builder<EmployeeProfile> */
    private function scoped(Access $actor): Builder
    {
        $query = EmployeeProfile::query();
        match ($actor->rowVisibility(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL)) {
            Access::ROWS_ALL => null,
            Access::ROWS_OWN => $query->whereKey($actor->ownEmployeeId()),
            Access::ROWS_NONE => $query->whereRaw('1 = 0'),
        };

        return $query;
    }

    private function validate(ReportCriteria $criteria): void
    {
        if (array_diff(array_keys($criteria->filters), self::FILTERS) !== []) {
            throw ValidationException::withMessages(['filters' => 'Choose supported employee report filters.']);
        }
        foreach (['analysis', 'groupBy', 'crossTab'] as $key) {
            if (array_key_exists($key, $criteria->filters) && $criteria->filters[$key] === '') {
                throw ValidationException::withMessages(['filters.'.$key => 'Choose a valid analysis option.']);
            }
        }
        $filters = array_filter($criteria->filters, static fn (string $value): bool => $value !== '');
        $rules = [];
        foreach (self::FILTERS as $key) {
            $rules[$key] = ['sometimes', 'string', 'max:150'];
        }
        $rules['analysis'] = ['sometimes', Rule::in(array_keys(self::analysisTopics()))];
        $rules['groupBy'] = ['sometimes', Rule::in(array_keys(self::groupings()))];
        $rules['crossTab'] = ['sometimes', Rule::in(array_keys(self::crossTabs()))];
        $rules['search'] = ['sometimes', 'string', 'max:200'];
        $rules['active'] = ['sometimes', Rule::in(['ALL', 'ACTIVE', 'INACTIVE'])];
        $rules['education'] = ['sometimes', Rule::in(['ALL', 'NONE', ...array_keys(EmployeeFields::EDUCATION_LEVELS)])];
        $rules['missing'] = ['sometimes', Rule::in(['ALL', 'ANY', ...array_keys(self::MISSING)])];
        foreach (['ageMin', 'ageMax', 'tenureMin', 'tenureMax'] as $key) {
            $rules[$key] = ['sometimes', 'integer', 'min:0', 'max:150'];
        }
        foreach (['hiredFrom', 'hiredTo'] as $key) {
            $rules[$key] = ['sometimes', 'date_format:Y-m-d'];
        }
        $validator = Validator::make($filters, $rules);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $key => $messages) {
                $errors['filters.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        foreach ([['ageMin', 'ageMax'], ['tenureMin', 'tenureMax'], ['hiredFrom', 'hiredTo']] as [$min, $max]) {
            if (isset($filters[$min], $filters[$max]) && $filters[$min] > $filters[$max]) {
                throw ValidationException::withMessages(['filters.'.$max => 'The upper bound must be at least the lower bound.']);
            }
        }
        Validator::make(['columns' => $criteria->columns, 'sort' => $criteria->sort, 'direction' => $criteria->direction], [
            'columns' => ['array', 'list', 'max:'.count(self::columns())],
            'columns.*' => ['string', 'distinct', Rule::in(array_keys(self::columns()))],
            'sort' => ['present', Rule::in(['', ...array_keys(self::columns())])],
            'direction' => [Rule::in(['asc', 'desc'])],
        ])->validate();
    }

    /** @param Builder<EmployeeProfile> $query
     * @param  array<string, string>  $filters
     */
    private function filterQuery(Builder $query, Access $actor, array $filters): void
    {
        foreach (preg_split('/\s+/', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $query->where(static function (Builder $part) use ($like): void {
                $part->where('employee_code', 'like', $like)->orWhere('employee_id_no', 'like', $like)
                    ->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                    ->orWhere('suffix', 'like', $like)->orWhere('position', 'like', $like)->orWhere('email', 'like', $like);
            });
        }
        $active = $filters['active'] ?? 'ALL';
        if ($active === 'ACTIVE' || $active === 'INACTIVE') {
            $query->where('is_active', $active === 'ACTIVE');
        }
        foreach (['employment' => 'employment_status', 'position' => 'position', 'worksite' => 'work_site_id', 'sex' => 'sex', 'civilStatus' => 'civil_status'] as $filter => $column) {
            $value = $filters[$filter] ?? 'ALL';
            if ($value === 'NONE') {
                $query->where(static fn (Builder $part): Builder => $part->whereNull($column)->orWhereRaw('TRIM('.$column.") = ''"));
            } elseif ($value !== '' && $value !== 'ALL') {
                $query->where($column, $value);
            }
        }
        $department = $filters['department'] ?? 'ALL';
        if ($department !== '' && $department !== 'ALL') {
            $ids = [];
            if (EmployeePlacements::ready()) {
                foreach (app(OrganizationDirectory::class)->positionChoices($actor) as $position) {
                    if ($department === 'NONE' ? $position->department_id === null : ($position->department_id === $department || $position->unit_id === $department)) {
                        $ids[] = $position->id;
                    }
                }
                $query->where(static function (Builder $part) use ($department, $ids): void {
                    $part->whereIn('position_id', $ids);
                    if ($department === 'NONE') {
                        $part->orWhereNull('position_id');
                    }
                });
            } elseif ($department !== 'NONE') {
                $query->whereRaw('1 = 0');
            }
        }
        foreach (['hiredFrom' => '>=', 'hiredTo' => '<='] as $key => $operator) {
            if (($filters[$key] ?? '') !== '') {
                $query->whereDate('date_hired', $operator, $filters[$key]);
            }
        }
    }

    /** @param array<string, mixed> $fact
     * @param  list<string>  $missing
     * @param  array<string, string>  $filters
     */
    private function matches(array $fact, array $missing, array $filters): bool
    {
        foreach (['age', 'tenure'] as $key) {
            foreach (['Min', 'Max'] as $bound) {
                $value = $filters[$key.$bound] ?? '';
                if ($value !== '' && ($fact[$key] === null || ($bound === 'Min' ? $fact[$key] < (int) $value : $fact[$key] > (int) $value))) {
                    return false;
                }
            }
        }
        $education = $filters['education'] ?? 'ALL';
        if ($education !== '' && $education !== 'ALL' && $fact['education_level'] !== $education) {
            return false;
        }
        $field = $filters['missing'] ?? 'ALL';

        return $field === '' || $field === 'ALL' || ($field === 'ANY' ? $missing !== [] : in_array($field, $missing, true));
    }

    private function years(string $start, string $end): ?int
    {
        return $start === '' || $start > $end ? null : (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->y;
    }

    /** @return array{string, string} */
    private function education(EmployeeProfile $employee): array
    {
        $rows = $employee->relationLoaded('educations') ? EmployeeFields::educationRows($employee) : [];
        foreach (array_reverse(EmployeeFields::EDUCATION_LEVELS, true) as $level => $label) {
            if (in_array($level, ['COLLEGE', 'GRADUATE'], true)) {
                $line = EmployeeFields::educationFallback($employee, $level);
                $parts = array_filter([$line['school'], $line['course']], static fn (string $part): bool => trim($part) !== '');
            } else {
                $matching = array_values(array_filter($rows, static fn (array $row): bool => $row['level'] === $level));
                usort($matching, static fn (array $left, array $right): int => [(int) ($right['year_graduated'] ?? $right['attended_to'] ?? 0), $right['id']] <=> [(int) ($left['year_graduated'] ?? $left['attended_to'] ?? 0), $left['id']]);
                $row = $matching[0] ?? [];
                $parts = array_filter([(string) ($row['school'] ?? ''), (string) ($row['course'] ?? '')], static fn (string $part): bool => trim($part) !== '');
            }
            if ($parts !== []) {
                return [$label.' — '.implode(' · ', $parts), $level];
            }
        }

        return ['', 'NONE'];
    }
}
