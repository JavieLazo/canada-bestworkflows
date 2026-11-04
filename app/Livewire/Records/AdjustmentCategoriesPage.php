<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Modules\Payroll\Application\Adjustments;
use App\Modules\Payroll\Application\Commands\DeleteAdjustmentCategory;
use App\Modules\Payroll\Application\Commands\SaveAdjustmentCategory;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\Queries\AdjustmentRecords;
use App\Modules\Payroll\Contracts\Views\AdjustmentCategoryView;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Adjustment Categories, under Payroll → Payroll Settings: what an
 * adjustment line can be — an earning, taxable or not, or a deduction —
 * named as the company names them. A list, an Add/Edit dialog and a delete
 * confirmation; whoever manages the Payroll Settings writes.
 */
#[Layout('layouts.app')]
#[Title('Adjustment Categories')]
class AdjustmentCategoriesPage extends Component
{
    public bool $dialogOpen = false;

    /** The category being edited, or null when adding. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $editingId = null;

    public string $formName = '';

    public string $formKind = AdjustmentRule::KIND_EARNING;

    public string $formTax = AdjustmentRule::TAX_TAXABLE;

    public ?string $formError = null;

    public bool $confirmOpen = false;

    /** The category queued for deletion. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deletingId = null;

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'form')) {
            $this->formError = null;
        }
    }

    public function openAdd(): void
    {
        if (! PayrollSettingsAccess::mayManage(app(Access::class))) {
            return;
        }
        $this->editingId = null;
        $this->formName = '';
        $this->formKind = AdjustmentRule::KIND_EARNING;
        $this->formTax = AdjustmentRule::TAX_TAXABLE;
        $this->formError = null;
        $this->dialogOpen = true;
    }

    public function startEdit(string $id): void
    {
        $access = app(Access::class);
        if (! PayrollSettingsAccess::mayManage($access)) {
            return;
        }
        $row = app(AdjustmentRecords::class)->categories($access)->first(static fn (AdjustmentCategoryView $c): bool => $c->id === $id);
        if ($row === null) {
            Flux::toast(variant: 'danger', text: 'That category no longer exists.');

            return;
        }
        $this->editingId = $row->id;
        $this->formName = $row->name;
        $this->formKind = $row->kind;
        $this->formTax = $row->tax ?? AdjustmentRule::TAX_TAXABLE;
        $this->formError = null;
        $this->dialogOpen = true;
    }

    public function save(): void
    {
        $access = app(Access::class);
        if (! PayrollSettingsAccess::mayManage($access)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        try {
            app(SaveAdjustmentCategory::class)->handle($access, $this->editingId, $this->formName, $this->formKind, $this->formKind === AdjustmentRule::KIND_DEDUCTION ? null : $this->formTax);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The category could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (RuntimeException $e) {
            $this->formError = $e->getMessage();

            return;
        }
        Flux::toast(variant: 'success', text: $this->editingId === null ? 'Category added.' : 'Category saved.');
        $this->dialogOpen = false;
        $this->editingId = null;
    }

    public function confirmDelete(string $id): void
    {
        if (! PayrollSettingsAccess::mayManage(app(Access::class))) {
            return;
        }
        $this->deletingId = $id;
        $this->confirmOpen = true;
    }

    public function deleteConfirmed(): void
    {
        $id = $this->deletingId;
        $this->confirmOpen = false;
        $this->deletingId = null;
        if ($id === null) {
            return;
        }
        try {
            $message = app(DeleteAdjustmentCategory::class)->handle(app(Access::class), $id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The category could not be deleted. Try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
    }

    public function render(): View
    {
        $access = app(Access::class);
        $rows = app(AdjustmentRecords::class)->categories($access);

        return view('livewire.records.adjustment-categories-page', [
            'rows' => $rows,
            'ready' => Adjustments::ready(),
            'canEdit' => PayrollSettingsAccess::mayManage($access),
            'deleting' => $this->deletingId === null ? null : $rows->first(fn (AdjustmentCategoryView $c): bool => $c->id === $this->deletingId),
        ]);
    }
}
