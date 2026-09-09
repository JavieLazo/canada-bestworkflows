<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Domain\Enums\AuditEvent;
use App\Livewire\Employees\EmployeeFields;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

/**
 * My Profile — the port of `src/app/(protected)/profile/profile-page.tsx`.
 *
 * Four short forms (Account, Personal, Emergency contact, Security) plus the
 * read-only Employment card off the 201 file. Every save compares against the
 * record first and answers "Nothing to save." when nothing differs, exactly as
 * the source's changedLabels() gate did.
 *
 * The actor is ALWAYS the session: there is no id anywhere on this component.
 * The employee row is resolved from `Access::employeeId` on every request — an
 * account with no linked record sees the Account and Security forms plus the
 * "appears here once an administrator links this account" note, never someone
 * else's rows.
 *
 * In API mode the page's own audit() calls were inert (`if (!DEMO_MODE)
 * return;`), and the server routes wrote the trail instead — so the audit rows
 * here carry the SERVER's sentences:
 *
 *   name    PATCH /api/accounts/[id]/name  "X changed their display name to Y"
 *   photo   PATCH /api/employees/[id]      "First Last — photo updated"
 *   fields  PATCH /api/employees/[id]      "First Last — phone, address, … updated"
 *   pass    POST  /api/auth/password       "X changed their own password"
 *
 * The password change applies the same two rules the source's form and its
 * `/api/auth/password` route shared (min 8, confirmation match), then verifies
 * the current password server-side, message for message. It writes through the
 * User model's `hashed` cast the same way the starter kit's Fortify-backed
 * security page does — there is no registered Fortify UpdateUserPassword
 * action in this app to route through, and Fortify's `Password::defaults()`
 * floor (12 in production) would contradict the reference's own copy
 * ("At least 8 characters."), which is the spec.
 */
#[Layout('layouts.app')]
#[Title('My Profile')]
class ProfilePage extends Component
{
    use WithFileUploads;

    /** hris.ts:33 — the Sex select's options. */
    public const array SEXES = ['Male', 'Female'];

    /** hris.ts:34 — the Civil status select's options. */
    public const array CIVIL_STATUSES = ['Single', 'Married', 'Widowed', 'Separated'];

    /** accounts/[id]/name Body: full_name max 200. */
    private const int FULL_NAME_CAP = 200;

    /** auth/password Body: both fields cap at 400. */
    private const int PASSWORD_CAP = 400;

    public string $fullName = '';

    /** @var array{phone: string, address: string, birthDate: string, sex: string, civilStatus: string} */
    public array $personal = [
        'phone' => '',
        'address' => '',
        'birthDate' => '',
        'sex' => '',
        'civilStatus' => '',
    ];

    /** @var array{name: string, relation: string, phone: string} */
    public array $emergency = [
        'name' => '',
        'relation' => '',
        'phone' => '',
    ];

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $confirmPassword = '';

    /**
     * A picked portrait waits here until the Account section is saved
     * (profile-page.tsx:92-98) — a Livewire temporary upload, staged, never
     * written to the record by the pick itself.
     *
     * @var TemporaryUploadedFile|null
     */
    public $photo = null;

    /**
     * The staged "Remove photo". Together with $photo this is the source's
     * `stagedPhoto` tri-state: upload = picked, flag = removed, neither =
     * whatever the record holds.
     */
    public bool $photoRemoved = false;

    public function mount(): void
    {
        $this->fullName = app(Access::class)->fullName;
        $this->seedFromEmployee($this->employee());
    }

    /**
     * The caller's own 201 row, keyed off the session — the port of
     * `employees.find((e) => e.id === me?.employeeId) ?? null`. Never a
     * client-writable id: an employee-less account resolves to null and sees
     * no employee sections at all.
     */
    private function employee(): ?EmployeeProfile
    {
        $employeeId = app(Access::class)->employeeId;

        return $employeeId === null
            ? null
            : EmployeeProfile::query()->whereKey($employeeId)->first();
    }

    /**
     * Re-seed the way the source's effects did after every save — "the row
     * comes back trimmed and nulled the way it was stored" — each section from
     * only the columns it owns.
     */
    private function seedFromEmployee(?EmployeeProfile $employee): void
    {
        $this->personal = [
            'phone' => $employee->phone ?? '',
            'address' => $employee->address ?? '',
            'birthDate' => $employee?->birth_date?->format('Y-m-d') ?? '',
            'sex' => $employee->sex ?? '',
            'civilStatus' => $employee->civil_status ?? '',
        ];

        $this->emergency = [
            'name' => $employee->emergency_name ?? '',
            'relation' => $employee->emergency_relation ?? '',
            'phone' => $employee->emergency_phone ?? '',
        ];
    }

    /**
     * Labels of the fields that differ from what the record already holds —
     * changedLabels() (profile-page.tsx:37-39).
     *
     * @param  list<array{0: string, 1: ?string, 2: ?string}>  $fields  [label, next, current]
     * @return list<string>
     */
    private function changedLabels(array $fields): array
    {
        $changed = [];

        foreach ($fields as [$label, $next, $current]) {
            if (($next ?? '') !== ($current ?? '')) {
                $changed[] = $label;
            }
        }

        return $changed;
    }

    /* ── Portrait ────────────────────────────────────────────────────────── */

    /**
     * Intake per lib/portrait.ts: accept an image up to 4 MB, message for
     * message. The pick only STAGES — the record is untouched until "Save
     * account", which is what stops a save in another section from silently
     * discarding a photo that was never written (profile-page.tsx:92-98).
     */
    public function updatedPhoto(): void
    {
        if ($this->photo === null) {
            return;
        }

        $upload = $this->photo;

        if (! str_starts_with((string) $upload->getMimeType(), 'image/')) {
            $this->photo = null;
            Flux::toast(text: 'Pick an image file.', variant: 'danger');

            return;
        }

        $size = (int) $upload->getSize();

        if ($size > EmployeeFields::MAX_PHOTO_BYTES) {
            $this->photo = null;
            Flux::toast(
                text: 'That image is '.EmployeeFields::humanSize($size).' — the limit is '
                    .EmployeeFields::humanSize(EmployeeFields::MAX_PHOTO_BYTES).'.',
                variant: 'danger',
            );

            return;
        }

        // The same decode the save-time resize will run, run now so an
        // unreadable file is refused at pick time, as portraitDataUrl() did.
        $probe = @imagecreatefromstring((string) file_get_contents($upload->getRealPath()));

        if ($probe === false) {
            $this->photo = null;
            Flux::toast(text: 'Could not read that image.', variant: 'danger');

            return;
        }

        imagedestroy($probe);

        // A fresh pick supersedes a staged removal (setStagedPhoto(data)).
        $this->photoRemoved = false;
    }

    /** The "Remove photo" button: stages null, applied by "Save account". */
    public function removeStagedPhoto(): void
    {
        $this->photo = null;
        $this->photoRemoved = true;
    }

    /** portrait.ts:29-63 in ~20 lines of raw GD (as Employees\Show ports it). */
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

    /* ── Account ─────────────────────────────────────────────────────────── */

    public function saveAccount(): void
    {
        $access = app(Access::class);
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $name = trim($this->fullName);

        if ($name === '') {
            Flux::toast(text: "Your full name can't be empty.", variant: 'danger');

            return;
        }

        $employee = $this->employee();

        $nameChanged = $name !== $access->fullName;
        $photoChanged = $employee !== null
            && ($this->photo !== null || ($this->photoRemoved && $employee->photo_path !== null));

        if (! $nameChanged && ! $photoChanged) {
            Flux::toast(text: 'Nothing to save.');

            return;
        }

        // The name route's schema cap (full_name max 200), before any write.
        if ($nameChanged && mb_strlen($name) > self::FULL_NAME_CAP) {
            Flux::toast(text: 'full_name: must be at most '.self::FULL_NAME_CAP.' characters.', variant: 'danger');

            return;
        }

        if ($nameChanged) {
            $user->name = $name;
            $user->save();

            // PATCH /api/accounts/[id]/name — the session's (old) name names
            // the actor, exactly as `session.fullName` did.
            AuditService::record(
                $access,
                AuditEvent::PROFILE_UPDATED,
                'user_accounts',
                $access->userId,
                "{$access->fullName} changed their display name to {$name}",
            );

            $this->fullName = $name;
        }

        if ($photoChanged) {
            if ($this->photo !== null) {
                $upload = $this->photo;
                $this->photo = null;

                try {
                    $jpeg = $this->portraitJpeg((string) file_get_contents($upload->getRealPath()));
                } catch (RuntimeException $e) {
                    Flux::toast(text: $e->getMessage(), variant: 'danger');

                    return;
                }

                $path = 'portraits/'.$employee->id.'.jpg';
                Storage::disk('public')->put($path, $jpeg);
                $employee->photo_path = $path;
            } else {
                if ($employee->photo_path !== null) {
                    Storage::disk('public')->delete($employee->photo_path);
                }

                $employee->photo_path = null;
            }

            $employee->save();
            $this->photoRemoved = false;

            AuditService::record(
                $access,
                AuditEvent::PROFILE_UPDATED,
                'employee_profiles',
                $employee->id,
                EmployeeName::display($employee).' — photo updated',
            );
        }

        Flux::toast(text: 'Account saved.', variant: 'success');
    }

    /* ── Personal ────────────────────────────────────────────────────────── */

    public function savePersonal(): void
    {
        $access = app(Access::class);
        $employee = $this->employee();

        if ($employee === null) {
            return;
        }

        // profile-page.tsx:242-248 — trimmed, empty stored as null. The whole
        // payload is sent, changed or not, exactly as the source sent it.
        $payload = [
            'phone' => trim($this->personal['phone']) !== '' ? trim($this->personal['phone']) : null,
            'address' => trim($this->personal['address']) !== '' ? trim($this->personal['address']) : null,
            'birth_date' => $this->personal['birthDate'] !== '' ? $this->personal['birthDate'] : null,
            'sex' => $this->personal['sex'] !== '' ? $this->personal['sex'] : null,
            'civil_status' => $this->personal['civilStatus'] !== '' ? $this->personal['civilStatus'] : null,
        ];

        $changed = $this->changedLabels([
            ['contact number', $payload['phone'], $employee->phone],
            ['address', $payload['address'], $employee->address],
            ['birth date', $payload['birth_date'], $employee->birth_date?->format('Y-m-d')],
            ['sex', $payload['sex'], $employee->sex],
            ['civil status', $payload['civil_status'], $employee->civil_status],
        ]);

        if ($changed === []) {
            Flux::toast(text: 'Nothing to save.');

            return;
        }

        $failure = $this->selfPatchError($employee, $payload);

        if ($failure !== null) {
            Flux::toast(text: $failure, variant: 'danger');

            return;
        }

        $employee->fill($payload)->save();

        // PATCH /api/employees/[id] details: every key the patch carried.
        AuditService::record(
            $access,
            AuditEvent::PROFILE_UPDATED,
            'employee_profiles',
            $employee->id,
            EmployeeName::display($employee).' — '.implode(', ', array_keys($payload)).' updated',
        );

        $this->seedFromEmployee($employee->refresh());
        Flux::toast(text: 'Personal details saved.', variant: 'success');
    }

    /* ── Emergency contact ───────────────────────────────────────────────── */

    public function saveEmergency(): void
    {
        $access = app(Access::class);
        $employee = $this->employee();

        if ($employee === null) {
            return;
        }

        $payload = [
            'emergency_name' => trim($this->emergency['name']) !== '' ? trim($this->emergency['name']) : null,
            'emergency_relation' => trim($this->emergency['relation']) !== '' ? trim($this->emergency['relation']) : null,
            'emergency_phone' => trim($this->emergency['phone']) !== '' ? trim($this->emergency['phone']) : null,
        ];

        $changed = $this->changedLabels([
            ['emergency contact name', $payload['emergency_name'], $employee->emergency_name],
            ['relationship', $payload['emergency_relation'], $employee->emergency_relation],
            ['emergency contact number', $payload['emergency_phone'], $employee->emergency_phone],
        ]);

        if ($changed === []) {
            Flux::toast(text: 'Nothing to save.');

            return;
        }

        $failure = $this->selfPatchError($employee, $payload);

        if ($failure !== null) {
            Flux::toast(text: $failure, variant: 'danger');

            return;
        }

        $employee->fill($payload)->save();

        AuditService::record(
            $access,
            AuditEvent::PROFILE_UPDATED,
            'employee_profiles',
            $employee->id,
            EmployeeName::display($employee).' — '.implode(', ', array_keys($payload)).' updated',
        );

        $this->seedFromEmployee($employee->refresh());
        Flux::toast(text: 'Emergency contact saved.', variant: 'success');
    }

    /**
     * The SelfPatch guards of api/employees/[id]/route.ts, for the columns
     * this page owns: the schema's text caps and the merged-date policy.
     *
     * @param  array<string, mixed>  $payload
     */
    private function selfPatchError(EmployeeProfile $employee, array $payload): ?string
    {
        foreach ($payload as $key => $value) {
            $cap = EmployeeFields::TEXT_CAPS[$key] ?? null;

            if ($cap !== null && is_string($value) && mb_strlen($value) > $cap) {
                return "{$key}: must be at most {$cap} characters.";
            }
        }

        if (! array_key_exists('birth_date', $payload)) {
            return null;
        }

        $day = static fn (?CarbonImmutable $d): ?string => $d?->format('Y-m-d');

        return EmployeeFields::employeeDateError([
            'birth_date' => is_string($payload['birth_date']) ? $payload['birth_date'] : null,
            'date_hired' => (string) $day($employee->date_hired),
            'date_regular' => $day($employee->date_regular),
            'date_resigned' => $day($employee->date_resigned),
        ], EmployeeFields::todayKey());
    }

    /* ── Security ────────────────────────────────────────────────────────── */

    public function changePassword(): void
    {
        $access = app(Access::class);
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        // The form's two courtesy rules, checked first so it answers
        // immediately (profile-page.tsx:337-347).
        if (mb_strlen($this->newPassword) < 8) {
            Flux::toast(text: 'Your new password must be at least 8 characters.', variant: 'danger');

            return;
        }

        if ($this->newPassword !== $this->confirmPassword) {
            Flux::toast(text: "The new password and its confirmation don't match.", variant: 'danger');

            return;
        }

        // The route's schema caps (auth/password Body: max 400 each).
        if (mb_strlen($this->currentPassword) > self::PASSWORD_CAP) {
            Flux::toast(text: 'current: must be at most '.self::PASSWORD_CAP.' characters.', variant: 'danger');

            return;
        }

        if (mb_strlen($this->newPassword) > self::PASSWORD_CAP) {
            Flux::toast(text: 'next: must be at most '.self::PASSWORD_CAP.' characters.', variant: 'danger');

            return;
        }

        // Verified server-side, which is the whole reason the endpoint existed.
        if (! Hash::check($this->currentPassword, (string) $user->getAuthPassword())) {
            Flux::toast(text: 'Your current password is not correct.', variant: 'danger');

            return;
        }

        // Through the model's `hashed` cast, as the starter kit's security
        // page writes it.
        $user->password = $this->newPassword;
        $user->save();

        // Fence every OTHER session, keeping this one. The reference binds
        // each session cookie to a SHA-256 marker of the password hash
        // (session.ts / guard.ts:63) and re-signs only the caller's cookie
        // (auth/password/route.ts:58-64), so all other devices are signed out
        // on their next request after a password change. SESSION_DRIVER is
        // database, so the equivalent fence is deleting the other rows; the
        // current session id is regenerated as the re-signed survivor.
        DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', '!=', session()->getId())
            ->delete();
        session()->regenerate();

        // Deliberately nothing about the password itself: the audit trail is
        // read by anyone holding the Audit module (auth/password route).
        AuditService::record(
            $access,
            AuditEvent::PASSWORD_CHANGED,
            'user_accounts',
            $access->userId,
            "{$access->fullName} changed their own password",
        );

        $this->reset('currentPassword', 'newPassword', 'confirmPassword');
        Flux::toast(text: 'Password changed. Use it the next time you sign in.', variant: 'success');
    }

    /* ── Render ──────────────────────────────────────────────────────────── */

    public function render(): View
    {
        $access = app(Access::class);
        $employee = $this->employee();

        // What the avatar shows: the staged pick, nothing while a removal is
        // staged, else the record (profile-page.tsx:152-153).
        $photoUrl = null;

        if ($this->photo !== null) {
            try {
                $photoUrl = $this->photo->temporaryUrl();
            } catch (Throwable) {
                $photoUrl = null;
            }
        } elseif (! $this->photoRemoved && $employee?->photo_path !== null) {
            $photoUrl = Storage::disk('public')->url($employee->photo_path);
        }

        $initials = $employee !== null ? EmployeeFields::initials($employee) : '';

        return view('livewire.profile.profile-page', [
            'employee' => $employee,
            'email' => $access->email,
            'photoUrl' => $photoUrl,
            'initials' => $initials !== '' ? $initials : '—',
            'employeeName' => EmployeeFields::employeeName($employee),
        ]);
    }
}
