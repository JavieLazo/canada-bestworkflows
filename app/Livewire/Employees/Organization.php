<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Modules\Workforce\Application\Queries\OrganizationChart;
use App\Support\Access;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The Organization view of Employee Records: every department as a card in a
 * wrapping grid, each opening into a table of its people — the department's
 * own first, then each unit's.
 *
 * The whole structure is rendered once and opened and closed in Alpine, so a
 * click never waits on the server. There is nothing to write here: who is
 * shown and whose name links to a record is the owner query's answer for a
 * fresh Access on every render.
 */
#[Title('Organization')]
class Organization extends Component
{
    public function render(): View
    {
        return view('livewire.employees.organization', [
            'departments' => app(OrganizationChart::class)->departments(app(Access::class)),
        ]);
    }
}
