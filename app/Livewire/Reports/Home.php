<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Reports\Support\HomeCharts;
use App\Livewire\Reports\Support\ReportCatalog;
use App\Support\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The Reports home says what the module offers before anything is asked of the reader: a few
 * graphs, then every report by name. A report the role's source grant does not reach is named
 * but not linked, so the reader knows it exists and what to ask for.
 */
#[Layout('layouts.app')]
#[Title('Reports')]
class Home extends Component
{
    public function render(): View
    {
        $actor = app(Access::class);
        abort_unless($actor->hasModule(ModuleCode::REPORTS), 403);
        $catalog = ReportCatalog::all();
        $columns = [];
        foreach (ReportCatalog::home() as $groups) {
            $column = [];
            foreach ($groups as $group) {
                $links = [];
                foreach ($group['links'] as $link) {
                    $links[] = [...$link,
                        'allowed' => ReportCatalog::allows($actor, $link['type']),
                        'needs' => $link['type'] === 'audit-logs' ? 'Needs full Audit Trail access' : 'Needs '.$catalog[$link['type']]['source'].' access',
                    ];
                }
                $column[] = ['heading' => $group['heading'], 'links' => $links];
            }
            $columns[] = $column;
        }

        return view('livewire.reports.home', ['columns' => $columns, 'charts' => HomeCharts::for($actor)]);
    }
}
