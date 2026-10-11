<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Domain\Enums\ModuleCode;
use App\Models\User;
use App\Modules\IdentityAccess\Application\Commands\ChangeOwnPassword;
use App\Modules\IdentityAccess\Application\PasswordPolicy;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/** My Profile reads the linked employee record and keeps account security separate from approved record changes. */
#[Layout('layouts.app')]
#[Title('My Profile')]
class ProfilePage extends Component
{
    public string $tab = 'record';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $confirmPassword = '';

    public bool $passwordDialogOpen = false;

    public bool $passwordChanged = false;

    public function mount(): void
    {
        $this->updatedTab((string) request()->query('tab', 'record'));
    }

    public function updatedTab(string $value): void
    {
        $this->tab = in_array($value, ['record', 'requests', 'account'], true) ? $value : 'record';
        if ($this->tab !== 'account') {
            $this->cancelPasswordChange();
        }
    }

    public function openPasswordChange(): void
    {
        $access = app(Access::class);
        abort_unless($access->hasModule(ModuleCode::PROFILE), 403);
        $this->clearPasswordDraft();
        $this->passwordChanged = false;
        $this->passwordDialogOpen = true;
    }

    public function cancelPasswordChange(): void
    {
        $this->passwordDialogOpen = false;
        $this->clearPasswordDraft();
    }

    public function updatedPasswordDialogOpen(bool $value): void
    {
        if (! $value) {
            $this->clearPasswordDraft();
        }
    }

    public function changePassword(): void
    {
        $access = app(Access::class);
        abort_unless($access->hasModule(ModuleCode::PROFILE), 403);
        $this->resetValidation();
        try {
            $changed = app(ChangeOwnPassword::class)->handle($access, $this->currentPassword, $this->newPassword, $this->confirmPassword, session()->getId());
        } catch (ValidationException $e) {
            $this->clearPasswordDraft();
            foreach ($e->errors() as $field => $messages) {
                $input = match ($field) {
                    'current_password' => 'currentPassword',
                    'password' => 'newPassword',
                    'confirmation', 'password_confirmation' => 'confirmPassword',
                    default => 'passwordChange',
                };
                foreach ($messages as $message) {
                    $this->addError($input, $message);
                }
            }

            return;
        } catch (QueryException $e) {
            report($e);
            $this->clearPasswordDraft();
            $this->addError('passwordChange', 'The password could not be changed. Try again.');

            return;
        } catch (RuntimeException $e) {
            $this->clearPasswordDraft();
            $this->addError('passwordChange', $e->getMessage());

            return;
        }
        if (! $changed) {
            $this->clearPasswordDraft();

            return;
        }
        // The command loads its own model. Keep the guard's cached credential
        // current before session middleware records the post-change hash.
        $user = auth()->user();
        if ($user instanceof User) {
            $user->refresh();
        }
        session()->regenerate();
        $this->cancelPasswordChange();
        $this->passwordChanged = true;
        Flux::toast(text: 'Password changed. Your other sessions have been signed out.', variant: 'success');
    }

    private function clearPasswordDraft(): void
    {
        $this->reset('currentPassword', 'newPassword', 'confirmPassword');
        $this->resetValidation();
    }

    public function render(): View
    {
        $access = app(Access::class);
        abort_unless($access->hasModule(ModuleCode::PROFILE), 403);

        return view('livewire.profile.profile-page', [
            'employee' => app(EmployeeRecords::class)->own($access),
            'email' => $access->email,
            'accountName' => $access->fullName,
            'passwordPolicy' => ['minLength' => PasswordPolicy::MIN_LENGTH, 'maxBytes' => PasswordPolicy::MAX_BYTES],
        ]);
    }
}
