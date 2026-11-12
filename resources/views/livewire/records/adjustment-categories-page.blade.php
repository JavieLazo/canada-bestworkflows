{{--
    Adjustment Categories — what an adjustment line can be: an earning,
    taxable or not, or a deduction after tax; the list, an Add/Edit dialog
    and a delete confirmation. Writes need Payroll access to all data.
--}}
@php
    use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
@endphp
<div class="space-y-6">
    @include('livewire.payroll.partials.settings-header', [
        'activeView' => 'adjustment-categories',
        'title' => 'Adjustment Categories',
        'description' => 'The kinds of adjustment a payroll can carry.',
    ])

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            @unless ($ready)
                <p class="w-full text-xs text-muted-foreground">Adjustment categories can be set up once this installation's upgrade finishes.</p>
            @endunless
            @unless ($canEdit)
                <p class="w-full text-xs text-muted-foreground">Your role can view the categories. Adding, editing and deleting require Payroll access to all data.</p>
            @endunless
            <h2 class="mr-auto text-base font-bold">Categories</h2>
            @if ($canEdit && $ready)
                <flux:button variant="primary" icon="plus" wire:click="openAdd">Add category</flux:button>
            @endif
        </header>
        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Kind</th>
                        <th>Tax</th>
                        <th>Adjustments</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($rows->isEmpty())
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="5" title="No category yet." />
                        </tr>
                    @endif
                    @foreach ($rows as $row)
                        <tr wire:key="category-{{ $row->id }}">
                            <td class="font-medium">{{ $row->name }}</td>
                            <td>{{ $row->kind === AdjustmentRule::KIND_DEDUCTION ? 'Deduction' : 'Earning' }}</td>
                            <td>{{ $row->kind === AdjustmentRule::KIND_DEDUCTION ? 'After tax' : ($row->tax === AdjustmentRule::TAX_TAXABLE ? 'Taxable' : 'Not compensation') }}</td>
                            <td class="num">{{ $row->adjustments_count }}</td>
                            <td class="text-right">
                                @if ($canEdit && $ready)
                                    <x-kit.row-actions :label="$row->name">
                                        <flux:menu.item icon="pencil-square" wire:click="startEdit('{{ $row->id }}')">Edit</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $row->id }}')" :disabled="$row->adjustments_count > 0">
                                            {{ $row->adjustments_count > 0 ? 'Delete · '.$row->adjustments_count.' '.($row->adjustments_count === 1 ? 'line' : 'lines') : 'Delete' }}
                                        </flux:menu.item>
                                    </x-kit.row-actions>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <flux:modal
        wire:model.self="dialogOpen"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'category-heading')"
    >
        <form class="grid gap-4" wire:submit="save">
            <flux:heading size="lg" id="category-heading">{{ $editingId === null ? 'Add category' : 'Edit category' }}</flux:heading>
            <div class="space-y-2">
                <flux:label for="category-name">Name</flux:label>
                <flux:input id="category-name" wire:model="formName" maxlength="120" placeholder="e.g. Correction of salary" />
            </div>
            <x-kit.select label="Kind" wire:model.live="formKind">
                <flux:select.option value="{{ AdjustmentRule::KIND_EARNING }}">Earning — added to the pay</flux:select.option>
                <flux:select.option value="{{ AdjustmentRule::KIND_DEDUCTION }}">Deduction — taken after the tax</flux:select.option>
            </x-kit.select>
            @if ($formKind === AdjustmentRule::KIND_EARNING)
                <x-kit.select label="Tax" wire:model="formTax">
                    <flux:select.option value="{{ AdjustmentRule::TAX_TAXABLE }}">Taxable, like pay</flux:select.option>
                    <flux:select.option value="{{ AdjustmentRule::TAX_NOT_TAXED }}">Not compensation, added after the tax</flux:select.option>
                </x-kit.select>
            @endif
            @if ($formError !== null)
                <p role="alert" class="text-sm text-danger">{{ $formError }}</p>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ $editingId === null ? 'Add' : 'Save changes' }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal
        wire:model.self="confirmOpen"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'category-delete-heading')"
    >
        <div class="space-y-4">
            <flux:heading size="lg" id="category-delete-heading">Delete this category?</flux:heading>
            <flux:text>{{ $deleting?->name ?? 'This category' }} is removed for good. No adjustment was made under it.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteConfirmed">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
