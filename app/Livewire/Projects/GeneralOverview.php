<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Modules\WorkManagement\Application\OverviewPeriod;
use App\Modules\WorkManagement\Application\Queries\OverviewAnalytics;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The top-level Project Management overview: how work moved over a picked
 * range, then where it stands today. Everything reads through the viewer's own
 * work and finance scope, so nobody sees totals they couldn't open.
 */
class GeneralOverview extends Component
{
    /** A scoped filter, not an access grant. */
    public string $portfolio = 'ALL';

    /** Plain filter state; OverviewPeriod::resolve() falls back to the default for anything it doesn't know. */
    #[Url(except: OverviewPeriod::DEFAULT)]
    public string $range = OverviewPeriod::DEFAULT;

    /** The applied custom range, only ever set by applyRange() after its check. */
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** What's typed in the picker, kept apart so a bad entry never replaces the range on screen. */
    public string $draftFrom = '';

    public string $draftTo = '';

    public function mount(): void
    {
        [$this->draftFrom, $this->draftTo] = [$this->from, $this->to];
    }

    public function pickRange(string $range): void
    {
        if (isset(OverviewPeriod::PRESETS[$range])) {
            [$this->range, $this->from, $this->to, $this->draftFrom, $this->draftTo] = [$range, '', '', '', ''];
            $this->resetErrorBag();
        }
    }

    public function applyRange(): void
    {
        $error = OverviewPeriod::customError($this->draftFrom, $this->draftTo, ManilaClock::todayKey());
        if ($error !== null) {
            $this->addError('range', $error);

            return;
        }
        [$this->range, $this->from, $this->to] = ['custom', $this->draftFrom, $this->draftTo];
        $this->resetErrorBag();
        $this->dispatch('overview-range-picked');
    }

    public function render(): View
    {
        $actor = Access::forActor(auth()->user());
        $today = ManilaClock::todayKey();
        $period = OverviewPeriod::resolve($this->range, $this->from, $this->to, $today);
        $analytics = app(OverviewAnalytics::class);

        return view('livewire.projects.general-overview', [
            'period' => $period,
            'trends' => $analytics->trends($actor, $period, $this->portfolio),
            'outlook' => $analytics->outlook($actor, $today, $this->portfolio),
            'workload' => app(WorkSummary::class)->workload($actor, null, $today, 6, 'ALL', $this->portfolio),
            'budgets' => $analytics->budgets($actor, $today, $this->portfolio),
        ]);
    }
}
