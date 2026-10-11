<?php

declare(strict_types=1);

namespace App\Livewire\Social;

use App\Domain\Enums\ModuleCode;
use App\Modules\Social\Application\Commands\ChangePrivacy;
use App\Modules\Social\Application\Privacy;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The "Community and Chat" card on My Profile: what coworkers see beside your
 * name in the two social screens.
 *
 * Their page pulls this in with one line, so everything it does stays here.
 * Someone whose role holds neither module sees nothing, since the choice has
 * nowhere to apply.
 */
class PrivacyCard extends Component
{
    public bool $showPosition = true;

    public bool $showBirthday = false;

    public bool $showAnniversary = false;

    public function mount(): void
    {
        if (! $this->available()) {
            return;
        }

        $chosen = app(Privacy::class)->of(app(Access::class));
        $this->showPosition = $chosen->showPosition;
        $this->showBirthday = $chosen->showBirthday;
        $this->showAnniversary = $chosen->showAnniversary;
    }

    public function save(): void
    {
        $actor = app(Access::class);

        if (! $actor->hasModule(ModuleCode::COMMUNITY, ModuleCode::CHAT)) {
            return;
        }

        // Same as the cards above it: say so rather than writing a row that
        // would change nothing.
        $chosen = app(Privacy::class)->of($actor);
        if ($chosen->showPosition === $this->showPosition && $chosen->showBirthday === $this->showBirthday && $chosen->showAnniversary === $this->showAnniversary) {
            Flux::toast(text: 'Nothing to save.');

            return;
        }

        app(ChangePrivacy::class)->handle($actor, $this->showPosition, $this->showBirthday, $this->showAnniversary);

        Flux::toast(text: 'Community and Chat settings saved.', variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.social.privacy-card', ['available' => $this->available()]);
    }

    private function available(): bool
    {
        return app(Access::class)->hasModule(ModuleCode::COMMUNITY, ModuleCode::CHAT);
    }
}
