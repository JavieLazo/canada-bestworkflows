<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Modules\Payroll\Application\Commands\SaveSignatory;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\Queries\SignatoryRecords;
use App\Modules\Payroll\Contracts\Views\SignatoryView;
use App\Modules\Payroll\Domain\Signatories\SignatorySlot;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Signatories — who signs the payroll documents, under Payroll → Payroll
 * Settings: the name and designation printed under "Prepared by", "Checked
 * by" and "Certified correct by" on every payslip and on the payroll
 * register. Any PAYROLL holder reads them; whoever manages the Payroll
 * Settings (PayrollSettingsAccess) edits the three slots in one form and
 * saves them together, each changed slot through the audited command.
 * Nothing here approves a payroll: the signatures are collected on paper.
 */
#[Layout('layouts.app')]
#[Title('Signatories')]
class SignatoriesPage extends Component
{
    /**
     * The three slots being typed, slot => [name, designation]. A wire:model
     * draft the save re-validates through the command.
     *
     * @var array<string, array{name: string, designation: string}>
     */
    public array $form = [];

    public function mount(): void
    {
        $this->loadForm();
    }

    public function save(): void
    {
        $access = app(Access::class);
        if (! PayrollSettingsAccess::mayManage($access)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        $changed = 0;
        try {
            foreach (SignatorySlot::all() as $slot) {
                $entry = $this->form[$slot] ?? [];
                $name = is_string($entry['name'] ?? null) ? $entry['name'] : '';
                $designation = is_string($entry['designation'] ?? null) ? $entry['designation'] : '';
                if (app(SaveSignatory::class)->handle($access, $slot, $name, $designation)) {
                    $changed++;
                }
            }
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The signatories could not be saved. Please try again.');
            $this->loadForm();

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
            $this->loadForm();

            return;
        }
        $this->loadForm();
        Flux::toast(variant: 'success', text: $changed === 0 ? 'Nothing changed.' : ($changed === 1 ? 'One signatory saved.' : "{$changed} signatories saved."));
    }

    public function render(): View
    {
        $access = app(Access::class);

        return view('livewire.records.signatories-page', [
            'signatories' => app(SignatoryRecords::class)->all($access),
            'ready' => SignatoryRecords::ready(),
            'canManage' => PayrollSettingsAccess::mayManage($access),
        ]);
    }

    /** The form as the slots stand. */
    private function loadForm(): void
    {
        $this->form = [];
        foreach (app(SignatoryRecords::class)->all(app(Access::class)) as $signatory) {
            /** @var SignatoryView $signatory */
            $this->form[$signatory->slot] = ['name' => $signatory->name, 'designation' => $signatory->designation];
        }
    }
}
