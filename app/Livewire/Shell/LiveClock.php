<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use App\Support\ManilaClock;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Ticking wall clock — the port of LiveClock.tsx. Renders bare text; the
 * caller decides whether it sits in a card, a header or beside a form.
 *
 * The tick is entirely client-side (Alpine, one setInterval per instance);
 * the server only paints the first frame so nothing flashes empty. Manila
 * wall time on both sides, per the timestamp rule.
 *
 *     <livewire:shell.live-clock />
 *     <livewire:shell.live-clock class="text-center" />
 */
class LiveClock extends Component
{
    /** Styling only — echoed (escaped) onto the root element. */
    public string $class = '';

    public function render(): View
    {
        $now = now()->setTimezone(ManilaClock::TZ);

        return view('livewire.shell.live-clock', [
            'initialTime' => $now->format('g:i:s A'),
            'initialDate' => $now->format('l, F j'),
        ]);
    }
}
