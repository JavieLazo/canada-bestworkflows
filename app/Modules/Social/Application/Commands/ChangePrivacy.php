<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\SocialPreference;
use App\Modules\Social\Application\SocialRules;
use App\Support\Access;
use App\Support\Realtime;

/**
 * Saves what the actor chose to show about themselves: their job title beside
 * their name, and their birthday and work anniversary in the "Coming up"
 * card.
 *
 * Always their own row, never anyone else's. The row is written even when the
 * answers match the defaults, so a deliberate choice survives a later change
 * of what the defaults are.
 */
final class ChangePrivacy
{
    public function handle(Access $actor, bool $showPosition, bool $showBirthday, bool $showAnniversary = false): void
    {
        $me = SocialRules::member($actor);

        $row = SocialPreference::query()->where('user_id', $me)->first();

        if ($row === null) {
            $row = new SocialPreference;
            $row->user_id = $me;
        }

        $row->fill(['show_position' => $showPosition, 'show_birthday' => $showBirthday, 'show_anniversary' => $showAnniversary]);

        if (! $row->exists || $row->isDirty()) {
            $row->save();
            // The feed draws names, titles and the "Coming up" card, so every
            // open Community page needs to redraw.
            Realtime::announce([Realtime::community()]);
        }
    }
}
