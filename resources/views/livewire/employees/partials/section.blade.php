{{--
    One record section, read or edit — the port of `SectionBlock`
    (employee-detail.tsx:400-494).

    Expects: $section, $employee, $editing (bool), $columns (1|2), $display
    (key → read text), $formState, $formErrors, $referenceOptions, $sites.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;
@endphp
<div class="p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-bold">{{ $section['title'] }}</h2>
            @if (! empty($section['description']))
                <p class="mt-1 text-sm text-muted-foreground">{{ $section['description'] }}</p>
            @endif
        </div>
        <div class="print:hidden">
            @if ($editing)
                <div class="flex items-center gap-2">
                    <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="cancelEditing">
                        Cancel
                    </flux:button>
                    <flux:button variant="primary" size="sm" wire:click="saveSection('{{ $section['id'] }}')">
                        Save
                    </flux:button>
                </div>
            @else
                <button
                    type="button"
                    wire:click="startEditing('{{ $section['id'] }}')"
                    class="text-sm font-medium text-primary hover:underline"
                >
                    Edit
                </button>
            @endif
        </div>
    </div>

    <dl class="mt-4 grid gap-4 {{ ($columns ?? 2) === 2 ? 'sm:grid-cols-2' : '' }}">
        @foreach ($section['fields'] as $field)
            @php $key = (string) $field['key']; @endphp
            @if (in_array($key, EmployeeFields::NAME_PARTS, true) && ! $editing)
                {{-- Read view shows one "Full Name" row; editing splits it
                     back into the three parts the record stores. --}}
                @if ($key === 'first_name')
                    <div wire:key="{{ $section['id'] }}-full-name" class="min-w-0">
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Full Name</dt>
                        <dd class="mt-1 break-words text-sm">{{ EmployeeFields::fullName($employee) }}</dd>
                    </div>
                @endif
            @elseif (isset($field['derived']))
                <div wire:key="{{ $section['id'] }}-{{ $key }}" class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $field['label'] }}</dt>
                    <dd class="mt-1 break-words text-sm">{{ $display[$key] ?? '—' }}</dd>
                </div>
            @else
                <div wire:key="{{ $section['id'] }}-{{ $key }}" class="contents">
                    @include('livewire.employees.partials.field', [
                        'field' => $field,
                        'editing' => $editing,
                        'display' => $display[$key] ?? '—',
                        'formState' => $formState,
                        'referenceOptions' => $referenceOptions,
                        'sites' => $sites,
                        'error' => $editing ? ($formErrors[$key] ?? null) : null,
                        'required' => false,
                    ])
                </div>
            @endif
        @endforeach
    </dl>
</div>
