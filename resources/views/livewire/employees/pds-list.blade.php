{{-- Typed personal-data list rows, with direct management edits and My Profile approval proposals. --}}
<section class="hris-card overflow-hidden">
    <header class="flex flex-wrap items-center justify-between gap-4 border-b border-border px-5 py-4">
        <div>
            <h2 class="text-base font-bold">{{ $spec['title'] }}</h2>
            <p class="text-sm text-muted-foreground">{{ $subtitle }}</p>
        </div>
        @if ($canEdit)
            <div class="flex flex-wrap items-center gap-2">
                <flux:button variant="primary" icon="plus" wire:click="openAdd">{{ $spec['add'] }}</flux:button>
            </div>
        @endif
    </header>

    <div class="hris-table-wrap hris-table-scroll">
        <table class="hris-table">
            @include('livewire.employees.partials.pds.'.$list, ['rows' => $rows, 'canEdit' => $canEdit, 'list' => $list])
        </table>
    </div>

    <x-kit.pagination :paginator="$rows" :noun="$noun" />

    @include('livewire.employees.partials.pds.dialog', [
        'list' => $list,
        'spec' => $spec,
        'fields' => $fields,
        'form' => $form,
        'formErrors' => $formErrors,
        'editingId' => $editingId,
        'legacy' => $legacy,
    ])

    @include('livewire.employees.partials.pds.confirm', [
        'deletingLabel' => $deletingLabel,
        'name' => $name,
    ])
</section>
