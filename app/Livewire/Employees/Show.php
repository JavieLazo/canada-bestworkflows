<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\PayrollRunMember;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * The full 201 file — the port of
 * `src/app/(protected)/employees/[id]/employee-detail.tsx`.
 *
 * One section edits at a time; email is checked as the field is left and
 * everything else waits for Save. The portrait is redrawn down to a 512px
 * JPEG with GD before it is stored (lib/portrait.ts), never kept as base64.
 *
 * Two callers with different rights land on the PATCH this mirrors
 * (api/employees/[id]/route.ts): HR (`all_data` + EMPLOYEES) edits anyone;
 * an employee edits their OWN record and only the SelfPatch fields — the
 * profile form must never become a way to award yourself a raise.
 */
#[Title('Employee record')]
class Show extends Component
{
    use WithFileUploads;

    /** What a person may change about themselves. Deliberately short (SelfPatch). */
    private const array SELF_PATCH = [
        'birth_date', 'sex', 'civil_status', 'address', 'phone', 'email',
        'residence_cert', 'emergency_name', 'emergency_relation', 'emergency_phone',
    ];

    #[Locked]
    public string $employeeId;

    public ?string $editingSection = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /**
     * Field-keyed messages for the section being edited.
     *
     * @var array<string, string>
     */
    public array $formErrors = [];

    public bool $deleteConfirmOpen = false;

    /**
     * The picked portrait (Livewire temporary upload).
     *
     * @var TemporaryUploadedFile|null
     */
    public $photo = null;

    public function mount(string $employee): void
    {
        $this->employeeId = $employee;
    }

    /**
     * The record, through the same visibility tiers as the list: everyone for
     * `all_data` + EMPLOYEES/PAYROLL, own record otherwise, nothing for an
     * unlinked account.
     */
    private function employee(): ?EmployeeProfile
    {
        $access = app(Access::class);
        $query = EmployeeProfile::query()->whereKey($this->employeeId);

        return match ($access->rowVisibility(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL)) {
            Access::ROWS_ALL => $query->first(),
            Access::ROWS_OWN => $query->whereKey($access->ownEmployeeId())->first(),
            Access::ROWS_NONE => null,
        };
    }

    private function isHr(Access $access): bool
    {
        return $access->mayReadAllData(ModuleCode::EMPLOYEES);
    }

    /* ── Section editing ─────────────────────────────────────────────────── */

    public function startEditing(string $sectionId): void
    {
        $employee = $this->employee();

        if ($employee === null || EmployeeFields::section($sectionId) === null) {
            return;
        }

        $this->form = EmployeeFields::toForm($employee);
        $this->formErrors = [];
        $this->editingSection = $sectionId;
    }

    public function cancelEditing(): void
    {
        $this->editingSection = null;
        $this->formErrors = [];
    }

    /** Email is checked as the field is left (employee-detail.tsx:100-110). */
    public function updated(string $name): void
    {
        if ($name === 'form.email') {
            $message = EmployeeFields::emailError((string) ($this->form['email'] ?? ''));

            if ($message !== null) {
                $this->formErrors['email'] = $message;
            } else {
                unset($this->formErrors['email']);
            }
        }
    }

    public function saveSection(string $sectionId): void
    {
        $access = app(Access::class);
        $employee = $this->employee();
        $section = EmployeeFields::section($sectionId);

        if ($employee === null || $section === null) {
            return;
        }

        // PATCH guard (api/employees/[id]/route.ts:60-63): HR may edit anyone;
        // anyone else only their own record.
        if (! $this->isHr($access) && $employee->id !== $access->employeeId) {
            abort(403);
        }

        // ── The section-level checks the page ran before submitting ────────
        $sectionErrors = [];

        foreach ($section['fields'] as $field) {
            if (($field['type'] ?? null) !== 'email') {
                continue;
            }

            $message = EmployeeFields::emailError((string) ($this->form[$field['key']] ?? ''));

            if ($message !== null) {
                $sectionErrors[$field['key']] = $message;
            }
        }

        if ($sectionId === 'employment' && trim((string) ($this->form['work_site_id'] ?? '')) === '') {
            $sectionErrors['work_site_id'] = 'Assigned Worksite is required.';
        }

        $this->formErrors = $sectionErrors;

        if ($sectionErrors !== []) {
            return;
        }

        // ── Payload (employee-detail.tsx:121-140) ──────────────────────────
        $boolean = EmployeeFields::booleanKeys();
        $numeric = EmployeeFields::numericKeys();
        $payload = [];

        foreach ($section['fields'] as $field) {
            if (isset($field['derived'])) {
                continue;
            }

            $key = (string) $field['key'];
            $value = $this->form[$key] ?? '';

            if (in_array($key, $boolean, true)) {
                $payload[$key] = EmployeeFields::asBool($value);
            } elseif (in_array($key, $numeric, true)) {
                $text = trim((string) $value);
                $n = $text === '' ? 0.0 : (is_numeric($text) ? (float) $text : NAN);

                if (! is_finite($n) || $n < 0) {
                    Flux::toast(text: "{$field['label']} must be a positive number.", variant: 'danger');

                    return;
                }

                $payload[$key] = $n;
            } else {
                $text = trim((string) $value);
                $payload[$key] = $text === '' ? null : $text;
            }
        }

        // A non-HR caller is restricted to the SelfPatch fields; anything
        // else fails the way the API's strict schema did.
        if (! $this->isHr($access)) {
            $extra = array_diff(array_keys($payload), self::SELF_PATCH);

            if ($extra !== []) {
                Flux::toast(
                    text: "Unrecognized key(s) in object: '".implode("', '", array_values($extra))."'",
                    variant: 'danger',
                );

                return;
            }
        }

        // ── The API-side guards, message for message ───────────────────────
        $failure = $this->serverPolicyError($employee, $payload);

        if ($failure !== null) {
            Flux::toast(text: $failure, variant: 'danger');

            return;
        }

        // date_hired is NOT NULL on the table (and non-optional in the API
        // schema); a cleared DateField must fail like the API's 400 did.
        if (array_key_exists('date_hired', $payload) && $payload['date_hired'] === null) {
            Flux::toast(text: 'Date joined must be a real calendar date.', variant: 'danger');

            return;
        }

        $employee->fill($payload)->save();

        AuditService::record(
            $access,
            $this->isHr($access) ? AuditEvent::EMPLOYEE_UPDATED : AuditEvent::PROFILE_UPDATED,
            'employee_profiles',
            $employee->id,
            EmployeeName::display($employee).' — '.implode(', ', array_keys($payload)).' updated',
        );

        $this->editingSection = null;
        Flux::toast(text: "{$section['title']} saved.", variant: 'success');
    }

    /**
     * The PATCH guards of api/employees/[id]/route.ts:70-108: schema caps,
     * merged-date policy, and the existence checks for whichever of the three
     * referenced values this patch carries.
     *
     * @param  array<string, mixed>  $payload
     */
    private function serverPolicyError(EmployeeProfile $employee, array $payload): ?string
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

        foreach (['sss_mode', 'philhealth_mode', 'pagibig_mode'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null
                && ! in_array($payload[$key], EmployeeFields::SERVER_MODES, true)) {
                return "{$key}: expected SEMI_MONTHLY or MONTHLY.";
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

        if (isset($payload['work_site_id']) && is_string($payload['work_site_id'])
            && WorkSite::query()->whereKey($payload['work_site_id'])->doesntExist()) {
            return 'Choose an existing work site for the employee.';
        }

        if (isset($payload['position']) && is_string($payload['position'])
            && Position::query()->where('name', $payload['position'])->doesntExist()) {
            return 'Choose a position from Records Management.';
        }

        if (isset($payload['employment_status']) && is_string($payload['employment_status'])
            && EmploymentStatusOption::query()->where('name', $payload['employment_status'])->doesntExist()) {
            return 'Choose an employment status from Records Management.';
        }

        return null;
    }

    /* ── Portrait ────────────────────────────────────────────────────────── */

    /**
     * Intake per lib/portrait.ts: accept up to 4 MB, but store a ≤512px JPEG
     * redrawn over white with raw GD — never base64 in a column.
     */
    public function updatedPhoto(): void
    {
        if ($this->photo === null) {
            return;
        }

        $access = app(Access::class);
        $employee = $this->employee();

        if ($employee === null) {
            return;
        }

        // SelfPatch includes `photo`, so a non-HR caller may replace their own.
        if (! $this->isHr($access) && $employee->id !== $access->employeeId) {
            abort(403);
        }

        $upload = $this->photo;
        $this->photo = null;

        if (! str_starts_with((string) $upload->getMimeType(), 'image/')) {
            Flux::toast(text: 'Pick an image file.', variant: 'danger');

            return;
        }

        $size = (int) $upload->getSize();

        if ($size > EmployeeFields::MAX_PHOTO_BYTES) {
            Flux::toast(
                text: 'That image is '.EmployeeFields::humanSize($size).' — the limit is '
                    .EmployeeFields::humanSize(EmployeeFields::MAX_PHOTO_BYTES).'.',
                variant: 'danger',
            );

            return;
        }

        try {
            $jpeg = $this->portraitJpeg((string) file_get_contents($upload->getRealPath()));
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $path = 'portraits/'.$employee->id.'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        $employee->photo_path = $path;
        $employee->save();

        // The server path folds a photo change into EMPLOYEE_UPDATED
        // (AuditEvent: EMPLOYEE_PHOTO_UPDATED is read-only, demo-era).
        AuditService::record(
            $access,
            $this->isHr($access) ? AuditEvent::EMPLOYEE_UPDATED : AuditEvent::PROFILE_UPDATED,
            'employee_profiles',
            $employee->id,
            EmployeeName::display($employee).' — photo updated',
        );

        Flux::toast(text: 'Photo updated.', variant: 'success');
    }

    /** portrait.ts:29-63 in ~20 lines of raw GD. */
    private function portraitJpeg(string $bytes): string
    {
        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new RuntimeException('Could not read that image.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, EmployeeFields::PORTRAIT_MAX_EDGE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        // Over white — transparent corners would go black otherwise.
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, EmployeeFields::PORTRAIT_JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($source);

        if ($jpeg === '') {
            throw new RuntimeException('Could not read that image.');
        }

        return $jpeg;
    }

    /* ── Delete ──────────────────────────────────────────────────────────── */

    public function deleteEmployee(): void
    {
        $access = app(Access::class);

        // requireAllDataModule("EMPLOYEES") — api/employees/[id]/route.ts:137.
        abort_unless($this->isHr($access), 403);

        $employee = $this->employee();

        if ($employee === null) {
            return;
        }

        if ($employee->is_active) {
            Flux::toast(text: 'Mark the employee inactive before deleting a test record.', variant: 'danger');

            return;
        }

        $historicalCount = $employee->accounts()->count()
            + $employee->logs()->count()
            + $employee->requests()->count()
            + $employee->payslips()->count()
            + $employee->documents()->count()
            + $employee->taskLoad()->count();

        $hasShift = $employee->shift()->exists();
        $onPayroll = PayrollRunMember::query()->where('employee_id', $employee->id)->exists();

        if ($historicalCount > 0 || $hasShift || $onPayroll) {
            Flux::toast(
                text: 'This employee has linked or historical records and must remain inactive instead of being deleted.',
                variant: 'danger',
            );

            return;
        }

        $name = EmployeeFields::employeeName($employee);

        if ($employee->photo_path !== null) {
            Storage::disk('public')->delete($employee->photo_path);
        }

        $employee->delete();

        AuditService::record(
            $access,
            AuditEvent::EMPLOYEE_DELETED,
            'employee_profiles',
            $this->employeeId,
            "{$name} employee record deleted",
        );

        $this->deleteConfirmOpen = false;
        Flux::toast(text: "{$name} deleted.", variant: 'success');
        $this->redirect(route('employees.index'));
    }

    /* ── Render ──────────────────────────────────────────────────────────── */

    public function render(): View
    {
        $employee = $this->employee();

        if ($employee === null) {
            return view('livewire.employees.show', ['employee' => null]);
        }

        $sites = WorkSite::query()->orderBy('name')->get(['id', 'name', 'radius_meters', 'geofence_exempt']);

        // Read-mode display text, precomputed per field key.
        $display = [];

        foreach (EmployeeFields::sections() as $section) {
            foreach ($section['fields'] as $field) {
                $key = (string) $field['key'];

                if (isset($field['derived'])) {
                    $display[$key] = EmployeeFields::derivedValue($employee, $field);
                } elseif (! empty($field['site'])) {
                    $siteId = $employee->getAttribute($key);
                    $display[$key] = $siteId === null || $siteId === ''
                        ? '—'
                        : ($sites->firstWhere('id', $siteId)->name ?? 'Unknown worksite');
                } else {
                    $display[$key] = EmployeeFields::readValue($employee, $field);
                }
            }
        }

        return view('livewire.employees.show', [
            'employee' => $employee,
            'sections' => EmployeeFields::sections(),
            'display' => $display,
            'accountsCount' => User::query()->where('employee_id', $employee->id)->count(),
            'referenceOptions' => [
                'positions' => Position::query()->orderBy('name')->pluck('name')->all(),
                'employment_statuses' => EmploymentStatusOption::query()->orderBy('name')->pluck('name')->all(),
            ],
            'sites' => $sites,
            'photoUrl' => $employee->photo_path
                ? Storage::disk('public')->url($employee->photo_path)
                : null,
        ]);
    }
}
