<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\WorkSite;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The five-step hire wizard — the port of
 * `src/app/(protected)/employees/add-employee-dialog.tsx`.
 *
 * Steps mirror the record page's sections, so nothing is asked for twice or
 * missed. The employee number is assigned by the server inside a transaction
 * (api/employees/route.ts:100-113); the dialog shows the number the new
 * record will get and retries once if someone else takes it first.
 */
class AddEmployeeDialog extends Component
{
    public bool $open = false;

    /**
     * Wizard position. Client-writable by design (the dots navigate); a
     * tampered value only shows another step — `create()` re-validates every
     * section server-side regardless.
     */
    public int $step = 0;

    /** @var array<string, mixed> */
    public array $form = [];

    /**
     * Quiet until the field has been visited, or Next was pressed.
     *
     * @var array<string, bool>
     */
    public array $touched = [];

    public bool $showAllErrors = false;

    public function mount(): void
    {
        $this->form = EmployeeFields::blankForm();
    }

    #[On('open-add-employee')]
    public function openDialog(): void
    {
        // Reserve the number when the dialog opens so the wizard shows what
        // the new record will be called (add-employee-dialog.tsx:93-96).
        $this->form['employee_code'] = EmployeeFields::nextEmployeeCode(
            EmployeeProfile::query()->pluck('employee_code')->all(),
        );
        $this->open = true;
    }

    /** Closing the dialog resets it (add-employee-dialog.tsx:196-199). */
    public function updatedOpen(bool $value): void
    {
        if (! $value) {
            $this->resetWizard();
        }
    }

    public function updated(string $name): void
    {
        // The Livewire stand-in for onBlur: text inputs sync on blur, so the
        // first update marks the field visited.
        if (str_starts_with($name, 'form.')) {
            $this->touched[substr($name, 5)] = true;
        }
    }

    private function resetWizard(): void
    {
        $this->form = EmployeeFields::blankForm();
        $this->touched = [];
        $this->showAllErrors = false;
        $this->step = 0;
    }

    /**
     * One message per offending field on a step, keyed by field
     * (add-employee-dialog.tsx:110-133).
     *
     * @return array<string, string>
     */
    public function stepErrors(int $step): array
    {
        $sections = EmployeeFields::sections();
        $section = $sections[$step] ?? null;

        if ($section === null) {
            return [];
        }

        $numeric = EmployeeFields::numericKeys();
        $errors = [];

        foreach ($section['fields'] as $field) {
            if (isset($field['derived'])) {
                continue;
            }

            $key = (string) $field['key'];
            $label = (string) $field['label'];
            $value = $this->form[$key] ?? '';
            $text = is_bool($value) ? ($value ? 'true' : 'false') : trim((string) $value);

            if (in_array($key, EmployeeFields::REQUIRED, true) && $text === '') {
                $errors[$key] = "{$label} is required.";

                continue;
            }

            if (in_array($key, $numeric, true) && $text !== '') {
                if (! is_numeric($text) || (float) $text < 0) {
                    $errors[$key] = "{$label} must be a positive number.";
                }
            }

            if (($field['type'] ?? null) === 'email') {
                $message = EmployeeFields::emailError($text);

                if ($message !== null) {
                    $errors[$key] = $message;
                }
            }
        }

        return $errors;
    }

    public function goNext(): void
    {
        if ($this->stepErrors($this->step) !== []) {
            $this->showAllErrors = true;

            return;
        }

        $this->showAllErrors = false;
        $this->step = min($this->step + 1, count(EmployeeFields::sections()) - 1);
    }

    public function goBack(): void
    {
        $this->showAllErrors = false;
        $this->step = max($this->step - 1, 0);
    }

    /** The dots: completed steps are clickable, future ones are not. */
    public function goTo(int $step): void
    {
        if ($step >= 0 && $step <= $this->step) {
            $this->step = $step;
        }
    }

    public function create(): void
    {
        $access = app(Access::class);

        // requireAllDataModule("EMPLOYEES") — api/employees/route.ts:75.
        abort_unless($access->mayReadAllData(ModuleCode::EMPLOYEES), 403);

        // Server-side authority over every step, not just the visible one —
        // the port of the API re-validating the whole body. A failing earlier
        // step is surfaced by jumping to it with its messages shown.
        foreach (array_keys(EmployeeFields::sections()) as $index) {
            if ($this->stepErrors($index) !== []) {
                $this->step = $index;
                $this->showAllErrors = true;

                return;
            }
        }

        $payload = $this->payload();

        $failure = $this->serverPolicyError($payload);

        if ($failure !== null) {
            Flux::toast(text: $failure, variant: 'danger');
            $this->step = 0;

            return;
        }

        try {
            $created = $this->insertWithFreshCode($payload);
        } catch (QueryException) {
            // Someone else may have taken the number since the dialog opened
            // (add-employee-dialog.tsx:157-176) — one retry with a recount.
            try {
                $created = $this->insertWithFreshCode($payload);
            } catch (QueryException $retry) {
                Flux::toast(text: 'That employee could not be saved. Try again.', variant: 'danger');
                $this->step = 0;

                return;
            }
        }

        // Server-path audit sentence (api/employees/route.ts:116-121).
        AuditService::record(
            $access,
            AuditEvent::EMPLOYEE_CREATED,
            'employee_profiles',
            $created->id,
            EmployeeName::display($created)." ({$created->employee_code}) hired",
        );

        Flux::toast(text: EmployeeName::display($created).' added.', variant: 'success');
        $this->dispatch('employee-created');
        $this->resetWizard();
        $this->open = false;
    }

    /**
     * The dialog's payload assembly (add-employee-dialog.tsx:140-155):
     * booleans as booleans, numerics as numbers (blank → 0), strings trimmed
     * with '' → null.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $boolean = EmployeeFields::booleanKeys();
        $numeric = EmployeeFields::numericKeys();
        $payload = [];

        foreach (EmployeeFields::allFields() as $field) {
            $key = (string) $field['key'];
            $value = $this->form[$key] ?? '';

            if (in_array($key, $boolean, true)) {
                $payload[$key] = EmployeeFields::asBool($value);
            } elseif (in_array($key, $numeric, true)) {
                $text = trim((string) $value);
                $payload[$key] = $text === '' || ! is_numeric($text) ? 0.0 : (float) $text;
            } else {
                $text = trim((string) $value);
                $payload[$key] = $text === '' ? null : $text;
            }
        }

        return $payload;
    }

    /**
     * The API-side guards of POST /api/employees, message for message:
     * schema caps, the calendar policy, and the three existence checks.
     *
     * @param  array<string, mixed>  $payload
     */
    private function serverPolicyError(array $payload): ?string
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

        foreach (['sss_mode', 'philhealth_mode', 'pagibig_mode'] as $key) {
            if ($payload[$key] !== null && ! in_array($payload[$key], EmployeeFields::SERVER_MODES, true)) {
                // The reference API's Zod enum rejects QUARTERLY even though
                // the select offers it — ported quirk (schemas.ts:80-82).
                return "{$key}: expected SEMI_MONTHLY or MONTHLY.";
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

        if ($payload['work_site_id'] === null
            || WorkSite::query()->whereKey($payload['work_site_id'])->doesntExist()) {
            return 'Choose an existing work site for the employee.';
        }

        if ($payload['position'] !== null
            && Position::query()->where('name', $payload['position'])->doesntExist()) {
            return 'Choose a position from Records Management.';
        }

        if ($payload['employment_status'] === null
            || EmploymentStatusOption::query()->where('name', $payload['employment_status'])->doesntExist()) {
            return 'Choose an employment status from Records Management.';
        }

        return null;
    }

    /**
     * The transactional insert of api/employees/route.ts:100-113: recount the
     * highest code inside the transaction, then create. `lockForUpdate` is the
     * MySQL stand-in for the source's advisory lock.
     *
     * @param  array<string, mixed>  $payload
     */
    private function insertWithFreshCode(array $payload): EmployeeProfile
    {
        return DB::transaction(function () use ($payload): EmployeeProfile {
            $codes = EmployeeProfile::query()->lockForUpdate()->pluck('employee_code')->all();
            $payload['employee_code'] = EmployeeFields::nextEmployeeCode($codes);

            return EmployeeProfile::query()->create($payload);
        });
    }

    public function render(): View
    {
        $sections = EmployeeFields::sections();
        $section = $sections[$this->step] ?? $sections[0];
        $errors = $this->stepErrors($this->step);

        $shown = [];

        foreach ($errors as $key => $message) {
            if ($this->showAllErrors || ($this->touched[$key] ?? false)) {
                $shown[$key] = $message;
            }
        }

        return view('livewire.employees.add-employee-dialog', [
            'sections' => $sections,
            'section' => $section,
            'shownErrors' => $shown,
            'isLast' => $this->step === count($sections) - 1,
            'referenceOptions' => [
                'positions' => Position::query()->orderBy('name')->pluck('name')->all(),
                'employment_statuses' => EmploymentStatusOption::query()->orderBy('name')->pluck('name')->all(),
            ],
            'sites' => WorkSite::query()->orderBy('name')->get(['id', 'name', 'radius_meters', 'geofence_exempt']),
        ]);
    }
}
