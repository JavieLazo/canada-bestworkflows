<?php

namespace App\Providers;

use App\Domain\Enums\AuditEvent;
use App\Models\User;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->wireLoginAudit();
    }

    /**
     * The sign-in audit rows and last_login_at stamp the reference writes in
     * its login route (api/auth/login/route.ts:60-103), attached to the auth
     * events Fortify fires. Payloads are the reference's, verbatim - including
     * the PORTED QUIRK that LOGIN_SUCCESS goes through anonymous() with
     * actor_id NULL even though the account is in hand (see AuditService).
     */
    protected function wireLoginAudit(): void
    {
        Event::listen(function (Login $event): void {
            $user = $event->user;

            if (! $user instanceof User) {
                return;
            }

            $user->forceFill(['last_login_at' => now()])->saveQuietly();

            AuditService::anonymous(
                (string) ($user->full_name ?? $user->name),
                AuditEvent::LOGIN_SUCCESS,
                'user_accounts',
                (string) $user->getKey(),
                ($user->full_name ?? $user->name).' signed in',
            );
        });

        Event::listen(function (Failed $event): void {
            $email = (string) ($event->credentials['email'] ?? 'unknown');

            AuditService::anonymous(
                $email,
                AuditEvent::LOGIN_FAILED,
                'user_accounts',
                null,
                'Invalid email or password.',
            );
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
