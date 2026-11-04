<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Modules\Timekeeping\Application\Commands\ApproveConversionTable;
use App\Modules\Timekeeping\Application\Commands\DeleteConversionTable;
use App\Modules\Timekeeping\Application\Commands\DeleteLeavePreset;
use App\Modules\Timekeeping\Application\Commands\SaveConversionTable;
use App\Modules\Timekeeping\Application\Commands\SaveLeavePreset;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveTypeCredits;
use App\Modules\Timekeeping\Application\ConversionTables;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Application\Queries\ConversionTableRecords;
use App\Modules\Timekeeping\Application\Queries\LeavePresetRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Contracts\Views\LeaveConversionTableView;
use App\Modules\Timekeeping\Contracts\Views\LeaveTypeView;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;
use App\Support\Access;
use App\Support\ManilaClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Records › Leave Credits: the company-wide rules of the leave-credits
 * flow — each leave type's credit rules (earns, unit, what may be charged
 * to it and in what order, below-zero limit), the minutes-to-days
 * conversion table versions (prepared by one person, approved by another)
 * and the earning presets an employee's policy is copied from. Each
 * employee's own policy is set in Leave Management › Leave policies.
 *
 * Every write re-resolves fresh Access and calls a Timekeeping command,
 * which checks LeaveSettingsAccess::mayManage for itself.
 */
#[Layout('layouts.app')]
#[Title('Leave Credits')]
class LeaveCreditSettingsPage extends Component
{
    /* ---- Leave type credit rules ---- */

    public bool $typeOpen = false;

    /** The leave type whose rules the dialog edits. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $typeId = null;

    /**
     * The dialog's draft: earns_credits, unit, charge_late, charge_absence, charge_order, below_zero_limit.
     *
     * @var array<string, mixed>
     */
    public array $typeForm = [];

    /* ---- Conversion tables ---- */

    public bool $tableOpen = false;

    /** The draft being edited, or null for a new version. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $tableId = null;

    /** The version whose rows the card shows, or null for the one in force. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $viewingTableId = null;

    /**
     * The dialog's draft: effective_from, hours_per_day, decimals, rounding, reference.
     *
     * @var array<string, string>
     */
    public array $tableForm = [];

    /**
     * The dialog's minute rows, 1..60 => fraction text.
     *
     * @var array<int, string>
     */
    public array $tableMinutes = [];

    /**
     * The dialog's hour rows, 1..n => fraction text.
     *
     * @var array<int, string>
     */
    public array $tableHours = [];

    public ?string $tableError = null;

    /* ---- Earning presets ---- */

    public bool $presetOpen = false;

    /** The preset the dialog edits, or null for a new one. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $presetId = null;

    /**
     * The preset dialog's draft (LeavePolicyForm shape).
     *
     * @var array<string, mixed>
     */
    public array $presetForm = [];

    public ?string $presetError = null;

    /* ------------------------------------------------------------------ */
    /* Leave type rules */
    /* ------------------------------------------------------------------ */

    public function openType(string $typeId): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access) || ! LeaveTypeCreditRecords::ready()) {
            return;
        }
        $type = app(LeaveTypeCreditRecords::class)->list($access)->first(static fn (LeaveTypeView $t): bool => $t->id === $typeId);
        if ($type === null) {
            return;
        }
        $this->typeId = $type->id;
        $this->typeForm = [
            'earns_credits' => (bool) $type->earns_credits,
            'unit' => $type->unit ?? LeaveTypeCredits::UNIT_DAYS,
            'charge_late' => (bool) $type->charge_late,
            'charge_absence' => (bool) $type->charge_absence,
            'charge_order' => (string) ($type->charge_order ?? 0),
            'below_zero_limit' => LeaveQuantity::format((float) ($type->below_zero_limit ?? 0.0)),
            'can_cash_out' => (bool) ($type->can_cash_out ?? false),
            'year_end' => (string) ($type->year_end ?? LeaveTypeCredits::YEAR_END_CARRY),
        ];
        $this->typeOpen = true;
    }

    public function saveType(): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access) || $this->typeId === null) {
            return;
        }
        try {
            $message = app(SaveLeaveTypeCredits::class)->handle($access, $this->typeId, [
                'earns_credits' => (bool) ($this->typeForm['earns_credits'] ?? false),
                'unit' => (string) ($this->typeForm['unit'] ?? ''),
                'charge_late' => (bool) ($this->typeForm['charge_late'] ?? false),
                'charge_absence' => (bool) ($this->typeForm['charge_absence'] ?? false),
                'charge_order' => (string) ($this->typeForm['charge_order'] ?? ''),
                'below_zero_limit' => (string) ($this->typeForm['below_zero_limit'] ?? ''),
                'can_cash_out' => (bool) ($this->typeForm['can_cash_out'] ?? false),
                'year_end' => (string) ($this->typeForm['year_end'] ?? LeaveTypeCredits::YEAR_END_CARRY),
            ]);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'The rules could not be saved. Try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        $this->typeOpen = false;
        $this->typeId = null;
        Flux::toast(text: $message, variant: 'success');
    }

    /* ------------------------------------------------------------------ */
    /* Conversion tables */
    /* ------------------------------------------------------------------ */

    public function viewTable(string $id): void
    {
        if (app(ConversionTableRecords::class)->find(app(Access::class), $id) !== null) {
            $this->viewingTableId = $id;
        }
    }

    public function openTable(?string $id = null): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access) || ! ConversionTables::ready()) {
            return;
        }
        $this->tableError = null;
        $row = $id === null ? null : app(ConversionTableRecords::class)->find($access, $id);
        if ($id !== null && ($row === null || $row->status !== 'DRAFT')) {
            return;
        }
        $this->tableId = $row?->id;
        if ($row === null) {
            $this->tableForm = ['effective_from' => '', 'hours_per_day' => '8', 'decimals' => '3', 'rounding' => 'HALF_DOWN', 'reference' => ''];
            $this->fillFromFormula();
        } else {
            $this->tableForm = [
                'effective_from' => $row->effective_from->format('Y-m-d'),
                'hours_per_day' => LeaveQuantity::format($row->hours_per_day),
                'decimals' => (string) $row->decimals,
                'rounding' => $row->rounding,
                'reference' => $row->reference ?? '',
            ];
            $rows = MinuteConversion::normalizeRows($row->rows, $row->hours_per_day, $row->decimals);
            $this->tableMinutes = $rows['minutes'];
            $this->tableHours = $rows['hours'];
        }
        $this->tableOpen = true;
    }

    /** Fills every row from the formula at the dialog's hours, decimals and rounding. */
    public function fillFromFormula(): void
    {
        if (! LeaveSettingsAccess::mayManage(app(Access::class))) {
            return;
        }
        $this->tableError = null;
        try {
            $rows = MinuteConversion::rows((float) ($this->tableForm['hours_per_day'] ?? 0), (int) ($this->tableForm['decimals'] ?? 0), (string) ($this->tableForm['rounding'] ?? ''));
        } catch (InvalidArgumentException $e) {
            $this->tableError = $e->getMessage();

            return;
        }
        $this->tableMinutes = $rows['minutes'];
        $this->tableHours = $rows['hours'];
    }

    public function saveTable(): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access)) {
            return;
        }
        try {
            $id = app(SaveConversionTable::class)->handle($access, $this->tableId, [
                ...$this->tableForm,
                'rows' => ['minutes' => $this->tableMinutes, 'hours' => $this->tableHours],
            ]);
        } catch (QueryException $e) {
            report($e);
            $this->tableError = 'The version could not be saved. Try again.';

            return;
        } catch (RuntimeException $e) {
            $this->tableError = $e->getMessage();

            return;
        }
        $this->tableOpen = false;
        $this->tableId = null;
        $this->viewingTableId = $id;
        Flux::toast(text: 'Conversion table draft saved. Approve it to take effect.', variant: 'success');
    }

    public function approveTable(string $id): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access)) {
            return;
        }
        try {
            $message = app(ApproveConversionTable::class)->handle($access, $id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'The version could not be approved. Try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        Flux::toast(text: $message, variant: 'success');
    }

    public function deleteTable(string $id): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access)) {
            return;
        }
        try {
            $message = app(DeleteConversionTable::class)->handle($access, $id);
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        if ($this->viewingTableId === $id) {
            $this->viewingTableId = null;
        }
        Flux::toast(text: $message, variant: 'success');
    }

    /* ------------------------------------------------------------------ */
    /* Earning presets */
    /* ------------------------------------------------------------------ */

    public function openPreset(?string $id = null): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access) || ! LeavePresetRecords::ready()) {
            return;
        }
        $this->presetError = null;
        $preset = $id === null ? null : app(LeavePresetRecords::class)->find($access, $id);
        if ($id !== null && $preset === null) {
            return;
        }
        $this->presetId = $preset?->id;
        $this->presetForm = Support\LeavePolicyForm::draft($preset === null ? [] : $preset->params, $preset === null ? '' : $preset->name, app(LeaveTypeCreditRecords::class)->list($access));
        $this->presetOpen = true;
    }

    public function savePreset(): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access)) {
            return;
        }
        try {
            $message = app(SaveLeavePreset::class)->handle($access, $this->presetId, (string) ($this->presetForm['name'] ?? ''), Support\LeavePolicyForm::params($this->presetForm));
        } catch (QueryException $e) {
            report($e);
            $this->presetError = 'The preset could not be saved. Try again.';

            return;
        } catch (RuntimeException $e) {
            $this->presetError = $e->getMessage();

            return;
        }
        $this->presetOpen = false;
        $this->presetId = null;
        Flux::toast(text: $message, variant: 'success');
    }

    public function deletePreset(string $id): void
    {
        $access = app(Access::class);
        if (! LeaveSettingsAccess::mayManage($access)) {
            return;
        }
        try {
            $message = app(DeleteLeavePreset::class)->handle($access, $id);
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        Flux::toast(text: $message, variant: 'success');
    }

    /** Adds a lookup-table row to one earning type in the preset dialog. */
    public function addPresetRow(string $token): void
    {
        if (! LeaveSettingsAccess::mayManage(app(Access::class))) {
            return;
        }
        $this->presetForm = Support\LeavePolicyForm::addRow($this->presetForm, $token);
    }

    public function removePresetRow(string $token, int $index): void
    {
        if (! LeaveSettingsAccess::mayManage(app(Access::class))) {
            return;
        }
        $this->presetForm = Support\LeavePolicyForm::removeRow($this->presetForm, $token, $index);
    }

    public function render(): View
    {
        $access = app(Access::class);
        $canManage = LeaveSettingsAccess::mayManage($access);
        $today = ManilaClock::todayKey();
        $types = app(LeaveTypeCreditRecords::class)->list($access);
        $tables = app(ConversionTableRecords::class)->list($access);
        $inForce = $tables->first(static fn (LeaveConversionTableView $t): bool => $t->status === 'APPROVED' && $t->effective_from->format('Y-m-d') <= $today);
        $shown = $this->viewingTableId !== null ? $tables->first(fn (LeaveConversionTableView $t): bool => $t->id === $this->viewingTableId) : $inForce;
        $differences = $shown === null ? [] : MinuteConversion::differences($shown->rows, $shown->hours_per_day, $shown->decimals, $shown->rounding);
        $presets = app(LeavePresetRecords::class)->list($access);

        return view('livewire.records.leave-credit-settings-page', [
            'canManage' => $canManage,
            'today' => $today,
            'typesReady' => LeaveTypeCreditRecords::ready(),
            'types' => $types,
            'typeName' => $this->typeId === null ? '' : (string) ($types->first(fn (LeaveTypeView $t): bool => $t->id === $this->typeId)->name ?? ''),
            'tablesReady' => ConversionTables::ready(),
            'tables' => $tables,
            'inForce' => $inForce,
            'shown' => $shown,
            'differences' => $differences,
            'presetsReady' => LeavePresetRecords::ready(),
            'presets' => $presets,
            'earningTypes' => $types->filter(static fn (LeaveTypeView $t): bool => (bool) $t->earns_credits)->values(),
            'chargeableLate' => LeaveTypeCreditRecords::chargeable('late'),
            'chargeableAbsence' => LeaveTypeCreditRecords::chargeable('absence'),
            'userId' => $access->userId,
        ]);
    }
}
