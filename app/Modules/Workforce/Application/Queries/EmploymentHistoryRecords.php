<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Modules\Workforce\Contracts\Views\EmploymentHistoryView;
use App\Modules\Workforce\Contracts\Views\EmploymentSubjectView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Scoped employment evidence, with changes compared in recorded order.
 *
 * Before-values are captured on new writes. For legacy rows the immediate
 * predecessor is selected in SQL independently of the page and report filters,
 * then loaded in one batch. Missing or discontinuous evidence stays unknown.
 *
 * One employee's timeline adds two reads beside the page of changes: who it
 * belongs to (subject) and the record it opens with (start). Both take the
 * same employee scope as every other read here, and neither feeds the count
 * or the report.
 */
final class EmploymentHistoryRecords
{
    /** @return array<string, string> */
    public static function fields(): array
    {
        return [
            'position' => 'Position',
            'position_level' => 'Position level',
            'employment_status' => 'Employment status',
            'work_site_id' => 'Worksite',
            'date_hired' => 'Date joined',
            'date_regular' => 'Date regularized',
            'date_resigned' => 'Date resigned',
            'is_active' => 'Active status',
            'monthly_rate' => 'Monthly rate',
            'daily_rate' => 'Daily rate',
            'record_change' => 'Employee record changes',
        ];
    }

    public static function validateFilters(string $from, string $to, string $field): void
    {
        $errors = [];
        foreach (['from' => $from, 'to' => $to] as $key => $date) {
            if ($date !== '' && ! EmployeeFields::isCalendarDay($date)) {
                $errors[$key] = 'Choose a valid calendar date.';
            }
        }
        if ($errors === [] && $from !== '' && $to !== '' && $to < $from) {
            $errors['to'] = 'The end date must be on or after the start date.';
        }
        if ($field !== '' && ! array_key_exists($field, self::fields())) {
            $errors['field'] = 'Choose a valid employment field.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return LengthAwarePaginator<int, EmploymentHistoryView> */
    public function page(Access $actor, string $employeeId = '', string $from = '', string $to = '', string $field = '', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        self::validateFilters($from, $to, $field);
        $perPage = max(1, min(100, $perPage));
        if (! Schema::hasTable('employee_employment_histories')) {
            return new LengthAwarePaginator([], 0, $perPage, 1);
        }

        $query = $this->filtered($actor, $employeeId, $from, $to, $field);
        $rows = $query->paginate($perPage, ['*'], 'page', max(1, $page));
        if ($rows->currentPage() > $rows->lastPage()) {
            $rows = $query->paginate($perPage, ['*'], 'page', $rows->lastPage());
        }

        return new LengthAwarePaginator($this->views($rows->getCollection(), $field),
            $rows->total(), $rows->perPage(), $rows->currentPage(), $rows->getOptions());
    }

    /**
     * Validation happens before returning the generator, so streaming callers
     * cannot send CSV headers and only then discover an invalid date range.
     *
     * @return Generator<int, EmploymentHistoryView>
     */
    public function rows(Access $actor, string $employeeId = '', string $from = '', string $to = '', string $field = ''): Generator
    {
        self::validateFilters($from, $to, $field);
        $exists = Schema::hasTable('employee_employment_histories');

        return (function () use ($actor, $employeeId, $from, $to, $field, $exists): Generator {
            if (! $exists) {
                return;
            }
            $query = $this->filtered($actor, $employeeId, $from, $to, $field);
            $cursor = null;
            do {
                $batchQuery = clone $query;
                if ($cursor !== null) {
                    $batchQuery->where(static function (Builder $next) use ($cursor): void {
                        $next->where('employee_employment_histories.created_at', '<', $cursor['created_at'])
                            ->orWhere(static function (Builder $sameTime) use ($cursor): void {
                                $sameTime->where('employee_employment_histories.created_at', $cursor['created_at'])
                                    ->where('employee_employment_histories.id', '<', $cursor['id']);
                            });
                    });
                }
                $batch = $batchQuery->limit(200)->get();
                foreach ($this->views($batch, $field) as $view) {
                    yield $view;
                }
                $last = $batch->last();
                $cursor = $last === null ? null : ['created_at' => $last->created_at->utc()->format('Y-m-d H:i:s.v'), 'id' => $last->id];
            } while ($batch->count() === 200);
        })();
    }

    /** @return Collection<int, EmploymentHistoryView> */
    public function forEmployee(Access $actor, string $employeeId): Collection
    {
        if (! Schema::hasTable('employee_employment_histories')) {
            return collect();
        }

        return $this->scoped($actor)->where('employee_id', $employeeId)
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(static fn (EmployeeEmploymentHistory $row): EmploymentHistoryView => Snapshot::of($row, EmploymentHistoryView::class));
    }

    /**
     * The employee one timeline belongs to, read from their record as it is
     * today under the history's own scope.
     *
     * A blank id means "the one person this viewer is limited to", which only
     * an own-rows viewer has. AN ID OUTSIDE THE SCOPE AND AN ID THAT DOES NOT
     * EXIST ANSWER ALIKE (null), so the page cannot confirm that one exists.
     */
    public function subject(Access $actor, string $employeeId = ''): ?EmploymentSubjectView
    {
        if ($employeeId === '') {
            if (! $actor->hasModule(ModuleCode::EMPLOYEES) || $actor->rowVisibility(ModuleCode::EMPLOYEES) !== Access::ROWS_OWN) {
                return null;
            }
            $employeeId = $actor->ownEmployeeId();
        }

        $employee = $this->visibleEmployees($actor)->whereKey($employeeId)->first([
            'id', 'employee_code', 'employee_id_no', 'first_name', 'last_name', 'suffix',
            'position', 'employment_status', 'is_active', 'date_hired', 'photo_path',
        ]);

        return $employee === null ? null : Snapshot::of($employee, EmploymentSubjectView::class, [
            'name' => EmployeeName::display($employee),
        ]);
    }

    /**
     * The record a timeline opens with: the employee's OLDEST row, and only
     * when that row is a hire or the backfilled baseline.
     *
     * It is read apart from page() and rows() so it never enters the update
     * count or the CSV. Its details are the stored snapshot, never today's
     * record: a worksite whose name was not kept stays unknown.
     */
    public function start(Access $actor, string $employeeId): ?EmploymentHistoryView
    {
        if ($employeeId === '' || ! Schema::hasTable('employee_employment_histories')) {
            return null;
        }

        $row = $this->scoped($actor)->where('employee_id', $employeeId)
            ->select('employee_employment_histories.*')->selectSub($this->successor(), 'next_id')
            ->orderBy('employee_employment_histories.created_at')->orderBy('employee_employment_histories.id')->first();
        if ($row === null || ! in_array($row->event_type, [EmploymentHistoryRecorder::HIRED, EmploymentHistoryRecorder::BASELINE], true)) {
            return null;
        }

        $values = self::values($row);
        $facts = [];
        foreach (self::fields() as $key => $label) {
            if ($key === 'record_change') {
                continue;
            }
            [$value, $known] = self::value($values, $key);
            $facts[] = ['field' => $key, 'label' => $label, 'value' => $value, 'known' => $known];
        }

        return Snapshot::of($row, EmploymentHistoryView::class, [
            'changes' => [],
            'changed_count' => 0,
            'is_latest' => $row->getAttribute('next_id') === null,
            'facts' => $facts,
        ]);
    }

    /** @return Builder<EmployeeProfile> */
    private function visibleEmployees(Access $actor): Builder
    {
        $employees = EmployeeProfile::query();
        if (! $actor->hasModule(ModuleCode::EMPLOYEES)) {
            return $actor->hasModule(ModuleCode::PROFILE) && $actor->hasEmployeeLink()
                ? $employees->whereKey($actor->ownEmployeeId())
                : $employees->whereRaw('1 = 0');
        }
        match ($actor->rowVisibility(ModuleCode::EMPLOYEES)) {
            Access::ROWS_ALL => null,
            Access::ROWS_OWN => $employees->whereKey($actor->ownEmployeeId()),
            Access::ROWS_NONE => $employees->whereRaw('1 = 0'),
        };

        return $employees;
    }

    /** @return Builder<EmployeeEmploymentHistory> */
    private function scoped(Access $actor): Builder
    {
        return EmployeeEmploymentHistory::query()->whereIn('employee_id', $this->visibleEmployees($actor)->select('id'));
    }

    /**
     * The row recorded immediately AFTER the outer one for the same employee,
     * of any event type and whatever the page's filters: the mirror of the
     * predecessor lookup in filtered(). A row with none is the latest record.
     *
     * @return Builder<EmployeeEmploymentHistory>
     */
    private function successor(): Builder
    {
        return EmployeeEmploymentHistory::query()->from('employee_employment_histories as successor')
            ->select('successor.id')->whereColumn('successor.employee_id', 'employee_employment_histories.employee_id')
            ->where(static function (Builder $later): void {
                $later->whereColumn('successor.created_at', '>', 'employee_employment_histories.created_at')
                    ->orWhere(static function (Builder $tie): void {
                        $tie->whereColumn('successor.created_at', 'employee_employment_histories.created_at')
                            ->whereColumn('successor.id', '>', 'employee_employment_histories.id');
                    });
            })->orderBy('successor.created_at')->orderBy('successor.id')->limit(1);
    }

    /** @return Builder<EmployeeEmploymentHistory> */
    private function filtered(Access $actor, string $employeeId, string $from, string $to, string $field): Builder
    {
        // Scope precedes every optional filter and every count/export read.
        $query = $this->scoped($actor)->whereIn('event_type', [EmploymentHistoryRecorder::UPDATED, EmploymentHistoryRecorder::RECORD_APPROVED]);
        if ($employeeId !== '') {
            $query->where('employee_id', $employeeId);
        }
        if ($from !== '') {
            $query->where('created_at', '>=', ManilaClock::instant($from.'T00:00:00')->utc()->format('Y-m-d H:i:s.v'));
        }
        if ($to !== '') {
            $query->where('created_at', '<', ManilaClock::instant($to.'T00:00:00')->addDay()->utc()->format('Y-m-d H:i:s.v'));
        }
        $fields = $field === '' ? array_keys(self::fields()) : [$field];
        $query->where(static function (Builder $any) use ($fields): void {
            foreach ($fields as $key) {
                $escaped = str_replace('_', '!_', $key);
                $any->orWhere(static function (Builder $match) use ($key, $escaped): void {
                    $match->where('changed_fields', $key)
                        ->orWhereRaw("changed_fields LIKE ? ESCAPE '!'", [$escaped.',%'])
                        ->orWhereRaw("changed_fields LIKE ? ESCAPE '!'", ['%,'.$escaped])
                        ->orWhereRaw("changed_fields LIKE ? ESCAPE '!'", ['%,'.$escaped.',%']);
                });
            }
        });
        $previous = EmployeeEmploymentHistory::query()->from('employee_employment_histories as previous')
            ->select('previous.id')->whereColumn('previous.employee_id', 'employee_employment_histories.employee_id')
            ->where(static function (Builder $earlier): void {
                $earlier->whereColumn('previous.created_at', '<', 'employee_employment_histories.created_at')
                    ->orWhere(static function (Builder $tie): void {
                        $tie->whereColumn('previous.created_at', 'employee_employment_histories.created_at')
                            ->whereColumn('previous.id', '<', 'employee_employment_histories.id');
                    });
            })->orderByDesc('previous.created_at')->orderByDesc('previous.id')->limit(1);

        return $query->select('employee_employment_histories.*')->selectSub($previous, 'previous_id')
            ->selectSub($this->successor(), 'next_id')
            ->with('employee:id,employee_code,employee_id_no,first_name,last_name,suffix')
            ->orderByDesc('employee_employment_histories.created_at')->orderByDesc('employee_employment_histories.id');
    }

    /** @param Collection<int, EmployeeEmploymentHistory> $rows
     * @return Collection<int, EmploymentHistoryView>
     */
    private function views(Collection $rows, string $field): Collection
    {
        $ids = $rows->filter(static fn (EmployeeEmploymentHistory $row): bool => $row->previous_values === null)
            ->pluck('previous_id')->filter()->unique()->values()->all();
        $previous = $ids === [] ? collect() : EmployeeEmploymentHistory::query()->whereKey($ids)->get()->keyBy('id');

        return $rows->map(static function (EmployeeEmploymentHistory $row) use ($previous, $field): EmploymentHistoryView {
            if ($row->event_type === EmploymentHistoryRecorder::RECORD_APPROVED) {
                // Proposal evidence already has frozen display values. A
                // distinct key keeps the legacy employment formatter from
                // interpreting a captured "Yes" as an inactive status.
                $changes = array_map(static fn (array $change): array => array_replace($change, ['field' => 'record.'.$change['field']]), $row->record_changes ?? []);

                return Snapshot::of($row, EmploymentHistoryView::class, [
                    'employee_name' => EmployeeName::display($row->employee),
                    'employee_code' => $row->employee->employee_code ?? '',
                    'employee_id_no' => $row->employee->employee_id_no ?? null,
                    'changes' => $changes,
                    'changed_count' => count($changes),
                    'is_latest' => $row->getAttribute('next_id') === null,
                ]);
            }
            $after = self::values($row);
            $changed = array_values(array_intersect(EmploymentHistoryRecorder::FIELDS, explode(',', $row->changed_fields ?? '')));
            $before = $row->previous_values;
            if ($before === null) {
                $prior = $previous->get((string) $row->getAttribute('previous_id'));
                if ($prior instanceof EmployeeEmploymentHistory && self::continuous($prior, $after, $changed)) {
                    $before = self::values($prior);
                    // A legacy name may predate a rename or come from the
                    // migration's current-name backfill. Only the explicit
                    // before snapshot proves its spelling at the transfer.
                    if (in_array('work_site_id', $changed, true)) {
                        unset($before['work_site_name']);
                    }
                }
            }
            $changes = [];
            foreach ($changed as $key) {
                if ($field !== '' && $key !== $field) {
                    continue;
                }
                [$old, $oldKnown] = self::value($before, $key);
                [$new, $newKnown] = self::value($after, $key);
                $changes[] = ['field' => $key, 'label' => self::fields()[$key], 'before' => $old, 'after' => $new,
                    'before_known' => $oldKnown, 'after_known' => $newKnown];
            }

            return Snapshot::of($row, EmploymentHistoryView::class, [
                'employee_name' => EmployeeName::display($row->employee),
                'employee_code' => $row->employee->employee_code ?? '',
                'employee_id_no' => $row->employee->employee_id_no ?? null,
                'changes' => $changes,
                'changed_count' => count($changed),
                'is_latest' => $row->getAttribute('next_id') === null,
            ]);
        });
    }

    /** @return array<string, string|null> */
    private static function values(EmployeeEmploymentHistory $row): array
    {
        $values = [];
        foreach ([...EmploymentHistoryRecorder::FIELDS, 'work_site_name'] as $field) {
            $value = $row->getAttribute($field);
            $values[$field] = match (true) {
                $value === null => null,
                $value instanceof CarbonImmutable => $value->format('Y-m-d'),
                is_bool($value) => $value ? '1' : '0',
                is_float($value) => number_format($value, 2, '.', ''),
                default => (string) $value,
            };
        }

        return $values;
    }

    /** @param array<string, string|null> $after
     * @param  list<string>  $changed
     */
    private static function continuous(EmployeeEmploymentHistory $prior, array $after, array $changed): bool
    {
        if (! in_array($prior->event_type, [EmploymentHistoryRecorder::BASELINE, EmploymentHistoryRecorder::HIRED, EmploymentHistoryRecorder::UPDATED], true)) {
            return false;
        }
        $before = self::values($prior);
        // The recorder names only values that really changed. An equal value
        // on a declared change contradicts this predecessor and may hide an
        // intervening edit, so it cannot establish a trustworthy before-value.
        foreach ($changed as $key) {
            if ($before[$key] === $after[$key]) {
                return false;
            }
        }
        foreach (array_diff(EmploymentHistoryRecorder::FIELDS, $changed) as $key) {
            if ($before[$key] !== $after[$key]) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string|null>|null $values
     * @return array{string|null, bool}
     */
    private static function value(?array $values, string $field): array
    {
        if ($values === null || ! array_key_exists($field, $values)) {
            return [null, false];
        }
        if ($field === 'work_site_id') {
            if ($values[$field] === null) {
                return [null, true];
            }
            $name = $values['work_site_name'] ?? null;

            return [$name, $name !== null && $name !== ''];
        }

        return [$values[$field], true];
    }
}
