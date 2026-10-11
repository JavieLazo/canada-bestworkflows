<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Models\EmployeeEducation;
use App\Models\EmployeeEligibility;
use App\Models\EmployeeTraining;
use App\Models\EmployeeVoluntaryWork;
use App\Models\EmployeeWorkExperience;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Modules\Workforce\Contracts\Views\PdsRowView;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Record;
use App\Support\Access;
use App\Support\EmployeeName;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Reads an employee's Personal Data Sheet lists (PdsLists) for the record's
 * list cards and the Personal Data Sheet print. The record's own visibility
 * rule (EmployeeRecords::find()) decides every read: a caller who cannot
 * open the record reads no row.
 *
 * Rows derived from the record or kept from legacy columns are assembled
 * here, never stored: Work Experience starts with the current job, read
 * from Employee Details; Educational Background shows every level in sheet
 * order, with the stored College and Graduate lines while no school of
 * that level is stored, and a level with neither as one empty row. Every
 * row leaves as a typed PdsRowView, so the screen and the print never
 * re-derive or re-format one.
 *
 * Eligibility, training and voluntary work are paged in SQL. Education and
 * work experience are assembled and paged in memory, which stays bounded:
 * at most PdsLists::MAX_ROWS stored rows per employee and list (SavePdsRow
 * and the hire hold it), plus at most five assembled rows.
 *
 * A list whose table does not exist yet reads as no stored rows.
 */
final class PdsRows
{
    /**
     * One page of one list, with what its card says around the rows: the
     * employee's display name, first and last with the suffix (subtitle
     * and confirm, as the Documents card names the employee), the footer's
     * noun — education's is "levels" while each level has exactly one row,
     * else "rows" — and, for education, each level's legacy line as one text
     * ("school · course · year") while its legacy row shows.
     *
     * @return array{rows: LengthAwarePaginator<int, PdsRowView>, name: string, noun: string, legacy: array<string, string>}
     */
    public function page(Access $actor, string $employeeId, string $list, int $page, int $perPage): array
    {
        $spec = PdsLists::spec($list);
        $pageName = $list.'Page';
        $employee = app(EmployeeRecords::class)->find($actor, $employeeId);

        if ($employee === null) {
            return ['rows' => QueryPage::items(collect(), $page, $perPage, $pageName), 'name' => '', 'noun' => $spec['noun'], 'legacy' => []];
        }

        $name = EmployeeName::display($employee);

        if ($list === 'education' || $list === 'experience') {
            $rows = $this->assembled($list, $employee);
            $legacy = [];

            foreach ($rows as $row) {
                if ($row->kind === 'legacy') {
                    $legacy[$row->level] = implode(' · ', array_filter(
                        [(string) $row->school, (string) $row->course, (string) $row->year_graduated],
                        static fn (string $part): bool => $part !== '',
                    ));
                }
            }

            $noun = $list === 'education'
                ? ($rows->count() === count(EmployeeEducation::LEVELS) ? 'levels' : 'rows')
                : $spec['noun'];

            return ['rows' => QueryPage::items($rows, $page, $perPage, $pageName), 'name' => $name, 'noun' => $noun, 'legacy' => $legacy];
        }

        $id = (string) $employee->id;
        $rows = match (true) {
            ! PdsLists::ready($list) => QueryPage::items(collect(), $page, $perPage, $pageName),
            $list === 'eligibility' => self::paged(self::ordered(EmployeeEligibility::query(), $list, $id), $list, $page, $perPage),
            $list === 'training' => self::paged(self::ordered(EmployeeTraining::query(), $list, $id), $list, $page, $perPage),
            default => self::paged(self::ordered(EmployeeVoluntaryWork::query(), $list, $id), $list, $page, $perPage),
        };

        return ['rows' => $rows, 'name' => $name, 'noun' => $spec['noun'], 'legacy' => []];
    }

    /** One stored row of the employee's list, or null when it is gone or the record is out of the caller's sight. */
    public function find(Access $actor, string $employeeId, string $list, string $id): ?PdsRowView
    {
        if (! in_array($list, PdsLists::KEYS, true) || ! PdsLists::ready($list)) {
            return null;
        }

        $employee = app(EmployeeRecords::class)->find($actor, $employeeId);

        if ($employee === null) {
            return null;
        }

        $model = PdsLists::spec($list)['model'];
        $row = $model::query()->where('employee_id', (string) $employee->id)->whereKey($id)->first();

        return $row === null ? null : self::view($row, $list, 'row');
    }

    /**
     * Every list, assembled exactly as on the record's cards but whole, and
     * the three Other info lists, for the Personal Data Sheet print.
     *
     * @return array{education: list<PdsRowView>, eligibility: list<PdsRowView>, experience: list<PdsRowView>, training: list<PdsRowView>, voluntary: list<PdsRowView>, other: array<string, list<string>>}
     */
    public function sheet(Access $actor, string $employeeId): array
    {
        $employee = app(EmployeeRecords::class)->find($actor, $employeeId, true);
        $sheet = ['education' => [], 'eligibility' => [], 'experience' => [], 'training' => [], 'voluntary' => [], 'other' => []];

        foreach (array_keys(EmployeeFields::ITEM_KINDS) as $key) {
            $sheet['other'][$key] = $employee === null ? [] : EmployeeFields::itemLines($employee, $key);
        }

        if ($employee === null) {
            return $sheet;
        }

        foreach (PdsLists::KEYS as $list) {
            $sheet[$list] = array_values($this->assembled($list, $employee)->all());
        }

        return $sheet;
    }

    /** @return Collection<int, PdsRowView> one whole list in card order */
    private function assembled(string $list, EmployeeProfileView $employee): Collection
    {
        $id = (string) $employee->id;
        $stored = PdsLists::ready($list) ? self::stored($list, $id) : collect();

        if ($list === 'experience') {
            return collect([self::currentJob($employee)])->concat($stored)->values();
        }

        if ($list !== 'education') {
            return $stored;
        }

        $byLevel = $stored->groupBy(static fn (PdsRowView $row): string => $row->level);
        $rows = collect();

        foreach (EmployeeEducation::LEVELS as $level) {
            $schools = $byLevel->get($level);

            if ($schools !== null && $schools->isNotEmpty()) {
                $rows = $rows->concat($schools);

                continue;
            }

            $legacy = self::legacySchool($employee, $level);
            $rows->push($legacy === null
                ? self::view(['level' => $level], 'education', 'empty')
                : self::view(['level' => $level] + $legacy, 'education', 'legacy'));
        }

        return $rows->values();
    }

    /**
     * The current job as Work Experience's first row, read from Employee
     * Details and never stored: position, the company, Date Joined to Date
     * Resigned (NULL reads "Present"), Rate Per Month when set, Position
     * Level as the pay grade, the employment status, Gov't service "N".
     */
    private static function currentJob(EmployeeProfileView $employee): PdsRowView
    {
        $rate = (float) $employee->getAttribute('monthly_rate');

        return self::view([
            'position_title' => $employee->getAttribute('position'),
            'company' => EmployeeFields::companyName(),
            'date_from' => $employee->getAttribute('date_hired'),
            'date_to' => $employee->getAttribute('date_resigned'),
            'monthly_salary' => $rate > 0 ? $rate : null,
            'pay_grade' => $employee->getAttribute('position_level'),
            'appointment_status' => EmployeeFields::employmentStatusLabel((string) $employee->getAttribute('employment_status')),
            'government_service' => false,
        ], 'experience', 'derived');
    }

    /**
     * The stored College or Graduate line (school, course, year, each
     * trimmed), or null when the level has none or is another level. Read
     * only: no form writes these columns, and nothing copies them.
     *
     * @return array{school: string, course: string, year_graduated: string}|null
     */
    private static function legacySchool(EmployeeProfileView $employee, string $level): ?array
    {
        $columns = match ($level) {
            EmployeeEducation::COLLEGE => ['college', 'college_course', 'college_year_graduated'],
            EmployeeEducation::GRADUATE => ['graduate_school', 'graduate_course', 'graduate_year_graduated'],
            default => null,
        };

        if ($columns === null) {
            return null;
        }

        $line = array_combine(
            ['school', 'course', 'year_graduated'],
            array_map(static fn (string $column): string => trim((string) $employee->getAttribute($column)), $columns),
        );

        return implode('', $line) === '' ? null : $line;
    }

    /**
     * The employee's stored rows of one list in card order: eligibilities
     * by date of exam, latest first, undated last; previous jobs and
     * trainings latest first; voluntary work latest first, undated last;
     * schools by the year attended from, earliest first, undated last
     * (assembled() puts them in level order). The later stored row comes
     * first on a tie, except for schools, which keep the order they were
     * added in.
     *
     * @return Collection<int, PdsRowView>
     */
    private static function stored(string $list, string $employeeId): Collection
    {
        $rows = match ($list) {
            'education' => self::ordered(EmployeeEducation::query(), $list, $employeeId)->get(),
            'eligibility' => self::ordered(EmployeeEligibility::query(), $list, $employeeId)->get(),
            'experience' => self::ordered(EmployeeWorkExperience::query(), $list, $employeeId)->get(),
            'training' => self::ordered(EmployeeTraining::query(), $list, $employeeId)->get(),
            default => self::ordered(EmployeeVoluntaryWork::query(), $list, $employeeId)->get(),
        };

        return $rows->map(static fn (Model $row): PdsRowView => self::view($row, $list, 'row'))->values();
    }

    /**
     * One list's rows of the employee, in the order stored() describes.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function ordered(Builder $query, string $list, string $employeeId): Builder
    {
        $query->where('employee_id', $employeeId);

        return match ($list) {
            'education' => $query->orderByRaw('attended_from is null')->orderBy('attended_from')->orderBy('created_at')->orderBy('id'),
            'eligibility' => $query->orderByRaw('exam_date is null')->orderByDesc('exam_date')->orderByDesc('created_at')->orderByDesc('id'),
            'voluntary' => $query->orderByRaw('date_from is null')->orderByDesc('date_from')->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderByDesc('date_from')->orderByDesc('date_to')->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * One page of a list paged in SQL: the first-pass snapshots QueryPage
     * makes are replaced by the typed views.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, PdsRowView>
     */
    private static function paged(Builder $query, string $list, int $page, int $perPage): LengthAwarePaginator
    {
        return QueryPage::get($query, $page, $perPage, PdsRowView::class, $list.'Page')
            ->through(static fn (PdsRowView $row): PdsRowView => self::view($row, $list, 'row'));
    }

    /**
     * The one normaliser every row passes through: a model, a first-pass
     * snapshot or an assembled array becomes a PdsRowView whose dates are
     * `YYYY-MM-DD` strings (a date cast arrives as a Carbon, which Blade
     * would print with its time), years integers (a legacy year stays the
     * text it was stored as), amounts and hours floats and Gov't service a
     * boolean; any other blank is NULL.
     *
     * @param  Model|Record|array<string, mixed>  $source
     */
    private static function view(Model|Record|array $source, string $list, string $kind): PdsRowView
    {
        $read = static fn (string $key): mixed => is_array($source) ? ($source[$key] ?? null) : $source->getAttribute($key);
        $id = $read('id');
        $values = ['id' => is_scalar($id) && $id !== '' ? (string) $id : null, 'list' => $list, 'kind' => $kind];

        foreach (PdsLists::fields($list) as $field) {
            $key = $field['key'];
            $value = $read($key);

            $values[$key] = match (true) {
                $key === 'level' => is_scalar($value) ? (string) $value : '',
                $key === 'government_service' => $value === null ? null : (bool) $value,
                $field['type'] === 'date' => self::day($value),
                $field['type'] === 'year' && $kind === 'legacy' => self::text($value),
                $field['type'] === 'year' => is_numeric($value) ? (int) $value : null,
                $field['type'] === 'money', $field['type'] === 'number' => is_numeric($value) ? (float) $value : null,
                default => self::text($value),
            };
        }

        return new PdsRowView($values);
    }

    private static function day(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        $text = self::text($value);

        return $text === null ? null : substr($text, 0, 10);
    }

    private static function text(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }
}
