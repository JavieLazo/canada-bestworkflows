<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\EmployeeDetailRows;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\EmployeeInput;
use App\Modules\Workforce\Application\EmployeePlacements;
use App\Modules\Workforce\Application\EmployeePosition;
use App\Modules\Workforce\Application\EmployeeProjection;
use App\Modules\Workforce\Application\EmployeeRules;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Application\PdsRowWriter;
use App\Modules\Workforce\Application\SalaryRates;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Validates every wizard step, then mints the employee code inside the
 * insert transaction — and inserts, in that same transaction, every part of
 * the hire: the children as `employee_children` rows, the details row
 * (`employee_pds_details`, with the spouse, parents and declarations), the
 * address rows (`employee_addresses`), the Other info lines
 * (`employee_other_items`) and the rows of each Personal Data Sheet list
 * (PdsLists, through PdsRowWriter). A part whose table does not exist yet
 * refuses with NOT_READY and rolls the whole hire back, so no employee is
 * left half hired. The position is linked by EmployeePosition, under the
 * structure mutex the transaction takes first. No legacy column — the one-line `address`,
 * `citizenship`, the spouse's name and employment, the college and graduate
 * lines — is written: no step carries them.
 */
final class CreateEmployee
{
    /** @param array<string, mixed> $form */
    public function handle(Access $actor, array $form): EmployeeProfileView
    {
        abort_unless($actor->mayReadAllData(ModuleCode::EMPLOYEES), 403);
        foreach (array_keys(EmployeeFields::wizard()) as $index) {
            $errors = EmployeeInput::stepErrors($form, $index);
            if ($errors !== []) {
                throw new RuntimeException(reset($errors));
            }
        }
        $payload = EmployeeInput::payload($form);
        // A hire answers only EmployeeFields::REQUIRED. What the record
        // cannot be saved without is filled in: the date joined is today
        // until HR enters the real one, and a blank employment status is
        // left to the column's own default.
        if (($payload['date_hired'] ?? null) === null) {
            $payload['date_hired'] = EmployeeFields::todayKey();
        }
        if (($payload['employment_status'] ?? null) === null) {
            unset($payload['employment_status']);
        }
        $error = EmployeeRules::creating($payload);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        // Not columns: the children, the details, the addresses, the Other
        // info lines and the list rows go in beside the profile, inside
        // insert(). The list rows are not record fields, so payload() never
        // sees them: PdsLists::rows() normalises each list the steps above
        // passed, rows left blank dropped.
        $children = EmployeeInput::children($form['children'] ?? null);
        $split = EmployeeInput::split($payload);
        $rows = [];
        foreach (PdsLists::KEYS as $list) {
            $rows[$list] = PdsLists::rows($list, $form['rows_'.$list] ?? []);
        }
        try {
            $employee = $this->insert($actor, $split['columns'], $children, $split['pds'], $split['addresses'], $split['items'], $rows);
        } catch (QueryException) {
            $employee = $this->insert($actor, $split['columns'], $children, $split['pds'], $split['addresses'], $split['items'], $rows);
        }
        AuditService::record($actor, AuditEvent::EMPLOYEE_CREATED, 'employee_profiles', $employee->id,
            EmployeeName::display($employee)." (employee {$employee->employee_code}) hired");

        $employee->load('children');
        if (EmployeeDetailRows::tablesReady()) {
            $employee->load(['pdsDetail', 'addresses']);
        }
        if (PdsLists::ready('education')) {
            $employee->load('educations');
        }
        if (PdsLists::ready('other')) {
            $employee->load('otherItems');
        }

        return EmployeeProjection::view($employee, true, EmployeePlacements::of(collect([$employee]))[$employee->id] ?? []);
    }

    /**
     * @param  array<string, mixed>  $columns
     * @param  list<array{name: string, birth_date: ?string}>  $children
     * @param  array<string, mixed>  $pds
     * @param  array{RESIDENTIAL: ?array<string, ?string>, PERMANENT: ?array<string, ?string>}|null  $addresses
     * @param  array<string, list<string>|null>  $items
     * @param  array<string, list<array<string, mixed>>>  $rows
     */
    private function insert(Access $actor, array $columns, array $children, array $pds, ?array $addresses, array $items, array $rows): EmployeeProfile
    {
        return DB::transaction(static function () use ($actor, $columns, $children, $pds, $addresses, $items, $rows): EmployeeProfile {
            // The structure mutex first (EmployeePosition::lock()), then every
            // profile row (the code mint): the lock order EmployeeDetailRows
            // expects.
            EmployeePosition::lock();
            $codes = EmployeeProfile::query()->lockForUpdate()->pluck('employee_code')->all();
            $columns['employee_code'] = EmployeeFields::nextEmployeeCode($codes);
            $choice = $columns[EmployeePosition::FORM_KEY] ?? null;
            unset($columns[EmployeePosition::FORM_KEY]);

            $employee = new EmployeeProfile;
            $employee->fill($columns);
            // The position re-read under the mutex: its id and current name.
            EmployeePosition::apply($employee, is_string($choice) ? $choice : null);
            $employee->save();
            if (! array_key_exists('employment_status', $columns)) {
                // Read back what the column's default gave the record.
                $employee->refresh();
            }
            foreach ($children as $position => $child) {
                $employee->children()->create(['position' => $position, 'name' => $child['name'], 'birth_date' => $child['birth_date']]);
            }
            $details = app(EmployeeDetailRows::class);
            $details->save($employee, $pds);
            if ($addresses !== null) {
                $details->replaceAddresses($employee, $addresses);
            }
            if ($items !== []) {
                $details->replaceItems($employee, $items);
            }
            $writer = app(PdsRowWriter::class);
            foreach ($rows as $list => $listRows) {
                foreach ($listRows as $row) {
                    $writer->insert($employee, $list, $row);
                }
            }
            app(EmploymentHistoryRecorder::class)->hired($actor, $employee);
            // The hire's rates are the first salary version, from the hire date.
            SalaryRates::recordProfileEdit($actor, $employee, null, null, $employee->date_hired->format('Y-m-d'));

            return $employee;
        });
    }
}
