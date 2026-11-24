{{--
    The payroll workspace's add-employees dialog (add-employees-dialog.tsx).
    The including component supplies the state and actions by these names:
    `picking`, `pickSearch`, `pickPosition`, `picked`, togglePick(),
    togglePickShown() and applyPicked(); the view supplies $pickerRows,
    $pickerPositions, $allShown, $photoUrl and $pickerNote (the sentence
    under the heading).
--}}
@use('App\Livewire\Payroll\Support\PayrollData')
<flux:modal
    wire:model.self="picking"
    class="w-full max-w-3xl"
    x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'add-employees-heading')"
>
    <div class="space-y-4">
        <div class="space-y-1.5">
            <h2 id="add-employees-heading" class="text-lg font-semibold leading-none tracking-tight">Add Employees</h2>
            <p class="text-sm text-muted-foreground">
                {{ $pickerNote }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:input
                aria-label="Search employees"
                placeholder="Search ID, name or position"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live="pickSearch"
            />
            <x-kit.filter-select label="Filter by Position" class="w-[14rem]" wire:model.live="pickPosition">
                <flux:select.option value="ALL">All positions</flux:select.option>
                @foreach ($pickerPositions as $position)
                    <flux:select.option value="{{ $position }}">{{ $position }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>
            <span class="num ml-auto text-xs text-muted-foreground">{{ count($picked) }} selected</span>
        </div>

        <div class="hris-table-wrap max-h-[22rem] overflow-y-auto rounded-md border border-border">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th class="w-10">
                            <input
                                type="checkbox"
                                aria-label="Select everyone shown"
                                class="size-4 rounded border-border accent-[var(--color-primary)]"
                                @checked($allShown)
                                @disabled($pickerRows->isEmpty())
                                wire:click="togglePickShown"
                            />
                        </th>
                        <th>Employee</th>
                        <th>Position</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($pickerRows->isEmpty())
                        <tr wire:key="pick-empty">
                            <td colspan="3" class="py-10 text-center text-muted-foreground">
                                No employee matches those filters.
                            </td>
                        </tr>
                    @endif
                    @foreach ($pickerRows as $e)
                        @php $on = in_array($e->id, $picked, true); @endphp
                        <tr
                            wire:key="pick-{{ $e->id }}"
                            class="cursor-pointer"
                            aria-selected="{{ $on ? 'true' : 'false' }}"
                            wire:click="togglePick('{{ $e->id }}')"
                        >
                            <td>
                                <input
                                    type="checkbox"
                                    aria-label="Select {{ PayrollData::employeeName($e) }}"
                                    class="size-4 rounded border-border accent-[var(--color-primary)]"
                                    @checked($on)
                                    x-on:click.stop
                                    wire:click="togglePick('{{ $e->id }}')"
                                />
                            </td>
                            {{-- No ID column: the identifier sits under the name,
                                 the way payroll/index.blade.php prints a run's
                                 code under the payroll name. It is the Employee
                                 ID, and the line is simply absent for someone
                                 the company has not numbered yet. --}}
                            @php $identity = \App\Support\EmployeeIdentity::besideName($e); @endphp
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                                        @if ($photoUrl($e))
                                            <img src="{{ $photoUrl($e) }}" alt="{{ PayrollData::employeeName($e) }}" class="h-full w-full object-cover" />
                                        @else
                                            <span class="num text-xs font-semibold">{{ PayrollData::initials($e) }}</span>
                                        @endif
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">{{ PayrollData::employeeName($e) }}</p>
                                        @if ($identity !== '')
                                            <p class="num truncate text-xs text-muted-foreground">{{ $identity }}</p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="text-muted-foreground">{{ $e->position ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex justify-end gap-2 border-t border-border pt-4">
            <flux:button variant="ghost" wire:click="$set('picking', false)">
                Cancel
            </flux:button>
            <flux:button variant="primary" icon="check" wire:click="applyPicked">
                Save Selection
            </flux:button>
        </div>
    </div>
</flux:modal>
