{{--
    One Personal Data Sheet list on its hire wizard step (AddEmployeeDialog):
    the record dialog's wording above, then one bordered block per row,
    headed "School 1", "School 2" … with its Remove button and the record
    dialog's own fields (partials/pds/fields) bound to
    `form.rows_<list>.<i>`. Add appends a blank draft up to the list's cap; a
    draft left entirely blank is not a row and is never stored, so the step
    may be passed with none. Each input is named by its label and its row
    ("Name of school — school 2"), because every row repeats the labels.

    Params:
        $list       the list key (PdsLists::KEYS)
        $rows       form.rows_<list>
        $spec       PdsLists::spec($list)
        $fields     PdsLists::fields($list)
        $max        PdsLists::MAX_ROWS
        $rowErrors  the shown field messages, keyed by row then field
        $listError  the shown message naming the first failing row, or null
--}}
<div class="space-y-4">
    <p class="text-sm text-muted-foreground">{{ $spec['dialog']['text'] }}</p>

    @forelse ($rows as $i => $row)
        <div wire:key="wiz-{{ $list }}-{{ $i }}" class="space-y-4 rounded-lg border border-border p-4">
            <div class="flex items-center justify-between gap-2">
                <h4 class="text-sm font-semibold">{{ ucfirst($spec['one']) }} {{ $i + 1 }}</h4>
                <flux:button
                    variant="ghost"
                    size="sm"
                    icon="x-mark"
                    wire:click="removeRow('{{ $list }}', {{ $i }})"
                    aria-label="Remove {{ $spec['one'] }} {{ $i + 1 }}"
                >
                    Remove
                </flux:button>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                @include('livewire.employees.partials.pds.fields', [
                    'fields' => $fields,
                    'model' => 'form.rows_'.$list.'.'.$i,
                    'fieldErrors' => $rowErrors[$i] ?? [],
                    'ariaSuffix' => ' — '.$spec['one'].' '.($i + 1),
                ])
            </div>
        </div>
    @empty
        <p class="text-sm text-muted-foreground">
            {{ $list === 'experience' ? 'No previous job recorded.' : 'None recorded · prints as N/A' }}
        </p>
    @endforelse

    @if (count($rows) < $max)
        <flux:button variant="outline" size="sm" icon="plus" wire:click="addRow('{{ $list }}')">
            {{ $spec['add'] }}
        </flux:button>
    @endif

    @if ($listError)
        <p class="text-xs text-danger">{{ $listError }}</p>
    @endif
</div>
