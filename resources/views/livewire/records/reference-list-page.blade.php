{{--
    One managed option list — the port of
    src/app/(protected)/records/reference-list.tsx, shared by Position,
    Document, Employment, Leave Settings and Payroll Deductions. Same columns
    in the same order (ID, {name}, Description?, Action), same search, same
    default sort (name, ascending), same dialog fields and copy, same
    delete-blocked-while-in-use menu item.
--}}
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">{{ $config['title'] }}</h1>
        <p class="text-sm text-muted-foreground">{{ $config['description'] }}</p>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                aria-label="Search {{ $config['plural'] }}"
                placeholder="Search code, name or description"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />

            <flux:button variant="primary" class="ml-auto" icon="plus" wire:click="openAdd">
                Add {{ $config['noun'] }}
            </flux:button>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="id" label="ID" />
                        <x-kit.sort-header field="name" :label="$config['nameLabel']" />
                        @if ($config['showDescription'])
                            <th>Description</th>
                        @endif
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($rows->total() === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state
                                :colspan="$config['showDescription'] ? 4 : 3"
                                title="No {{ $config['noun'] }} matches those filters."
                            />
                        </tr>
                    @endif
                    @foreach ($rows as $row)
                        @php $usedBy = $usage[$row->getKey()] ?? 0; @endphp
                        <tr wire:key="row-{{ $row->getKey() }}">
                            <td class="num whitespace-nowrap align-middle">{{ $row->code }}</td>
                            <td class="font-medium">{{ $row->name }}</td>
                            @if ($config['showDescription'])
                                <td class="text-muted-foreground">{{ $row->description ?? '—' }}</td>
                            @endif
                            <td class="text-right">
                                <x-kit.row-actions :label="$row->name">
                                    <flux:menu.item icon="pencil" wire:click="startEdit('{{ $row->getKey() }}')">
                                        Edit
                                    </flux:menu.item>
                                    {{-- RowActions.tsx:42-49 — blocked (and not painted red)
                                         while records still carry the option. --}}
                                    <flux:menu.item
                                        icon="trash"
                                        :variant="$usedBy > 0 ? 'default' : 'danger'"
                                        :disabled="$usedBy > 0"
                                        wire:click="confirmDelete('{{ $row->getKey() }}')"
                                    >
                                        {{ $usedBy > 0 ? "Delete · in use by {$usedBy}" : 'Delete' }}
                                    </flux:menu.item>
                                </x-kit.row-actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$rows" :noun="$config['plural']" />
    </section>

    {{-- The AlertDialog (reference-list.tsx:280-306). --}}
    <flux:modal wire:model.self="confirmOpen" class="w-full max-w-md">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">Delete {{ $deleting?->name }}?</flux:heading>
                <flux:text>
                    {{ $deleting?->code }} is removed from the list for good. Nothing currently
                    uses it, so no record changes &mdash; but this cannot be undone.
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="deleteConfirmed">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- The ReferenceDialog (reference-list.tsx:326-414). --}}
    <flux:modal wire:model.self="dialogOpen" class="w-full max-w-lg">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">
                    {{ $editing !== null ? "Edit {$config['noun']}" : "Add {$config['noun']}" }}
                    @if ($editing !== null)
                        <span class="num ml-2 text-sm font-normal text-muted-foreground">{{ $editing->code }}</span>
                    @endif
                </flux:heading>
                <flux:text>
                    {{ $editing !== null
                        ? 'Renaming updates the option everywhere it is offered.'
                        : "The new {$config['noun']} becomes selectable across the system." }}
                </flux:text>
            </div>

            <form class="space-y-4" wire:submit="save">
                <div class="space-y-2">
                    <flux:label>{{ $config['nameLabel'] }}</flux:label>
                    <flux:input
                        wire:model="formName"
                        aria-invalid="{{ $formError !== null ? 'true' : 'false' }}"
                        class:input="{{ $formError !== null ? 'border-danger!' : '' }}"
                    />
                    @if ($formError !== null)
                        <p class="text-xs text-danger">{{ $formError }}</p>
                    @endif
                </div>

                <div @class(['space-y-2' => $config['showDescription'], 'hidden' => ! $config['showDescription']])>
                    <flux:label>Description</flux:label>
                    <flux:textarea rows="2" wire:model="formDescription" />
                </div>

                <flux:button
                    type="submit"
                    variant="primary"
                    :icon="$editing !== null ? 'pencil' : 'plus'"
                >
                    {{ $editing !== null ? 'Save changes' : "Add {$config['noun']}" }}
                </flux:button>
            </form>
        </div>
    </flux:modal>
</div>
