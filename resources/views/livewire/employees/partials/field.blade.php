{{--
    One employee field, read or edit — the port of `Field` in
    employee-fields.tsx:305-512.

    Expects:
        $field            the spec array (key, label, type?, options?, source?, site?, wide?, auto?)
        $editing          bool
        $display          read-mode text (precomputed by the caller; ignored while editing)
        $formState        the Livewire form array (edit mode)
        $referenceOptions ['positions' => [...names], 'employment_statuses' => [...names]]
        $sites            collection of WorkSite (id, name, radius_meters, geofence_exempt)
        $error            ?string — shown under the control, reddens its border
        $required         bool — asterisk on the label

    Flux-free approximations, noted per control:
      - the source's shadcn Select is Flux's native select; a "placeholder"
        is a hidden disabled first option;
      - ManagedSelect's empty-list note renders as a disabled option;
      - the popover DateField degrades to <x-kit.date-field> (typed input +
        native picker), per the shared kit.
--}}
@php
    $key = (string) $field['key'];
    $type = $field['type'] ?? null;
    $wide = ! empty($field['wide']) ? 'sm:col-span-2' : '';
    $current = $formState[$key] ?? '';
@endphp
@if (! $editing)
    <div class="min-w-0 {{ $wide }}">
        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $field['label'] }}</dt>
        <dd class="mt-1 break-words text-sm {{ \App\Livewire\Employees\EmployeeFields::isNumFace($field) ? 'num' : '' }}">{{ $display }}</dd>
    </div>
@else
    <div class="space-y-2 {{ $wide }}">
        <flux:label>
            {{ $field['label'] }}
            @if ($required)
                <span aria-hidden="true" class="ml-0.5 text-danger">*</span>
                <span class="sr-only">(required)</span>
            @endif
        </flux:label>

        @if (! empty($field['auto']))
            {{-- System-assigned: shown so the number is known, disabled so it
                 reads as filled-in rather than fillable. Not submitted. --}}
            <flux:input disabled class:input="num" value="{{ $current }}" aria-label="{{ $field['label'] }}" />
            <p class="text-xs text-muted-foreground">Assigned automatically.</p>
        @elseif ($type === 'date')
            <x-kit.date-field
                wire:model="form.{{ $key }}"
                aria-label="{{ $field['label'] }}"
                class="{{ $error ? '[&_input]:border-danger!' : '' }}"
            />
        @elseif ($type === 'money')
            <x-kit.money-input
                wire:model="form.{{ $key }}"
                :aria-label="$field['label']"
                :aria-invalid="(bool) $error"
                class="{{ $error ? 'border-danger!' : '' }}"
            />
        @elseif ($type === 'switch')
            {{-- Boolean on the record, Yes/No in the UI (employee-fields.tsx:398-409). --}}
            <flux:select wire:model.live="form.{{ $key }}" aria-label="{{ $field['label'] }}">
                <flux:select.option value="true" :selected="\App\Livewire\Employees\EmployeeFields::asBool($current)">Yes</flux:select.option>
                <flux:select.option value="false" :selected="! \App\Livewire\Employees\EmployeeFields::asBool($current)">No</flux:select.option>
            </flux:select>
        @elseif (isset($field['source']))
            @php
                $names = $referenceOptions[$field['source']] ?? [];
                if ($current !== '' && ! in_array($current, $names, true)) {
                    // The value already on the record is always offered, even
                    // once it is off the list (ManagedSelect, employee-fields.tsx:262-303).
                    array_unshift($names, $current);
                }
            @endphp
            <flux:select
                wire:model.live="form.{{ $key }}"
                aria-label="{{ $field['label'] }}"
                class="{{ $error ? 'border-danger!' : '' }}"
            >
                <flux:select.option value="" disabled hidden>Not set</flux:select.option>
                @if ($names === [])
                    <flux:select.option value="" disabled>Nothing on this list yet — add it in Records Management.</flux:select.option>
                @endif
                @foreach ($names as $name)
                    <flux:select.option value="{{ $name }}">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        @elseif (! empty($field['site']))
            <flux:select
                wire:model.live="form.{{ $key }}"
                aria-label="{{ $field['label'] }}"
                class="{{ $error ? 'border-danger!' : '' }}"
            >
                <flux:select.option value="">No assigned worksite</flux:select.option>
                @foreach ($sites as $site)
                    <flux:select.option value="{{ $site->id }}">{{ $site->name }}{{ $site->geofence_exempt ? ' (exempt)' : ' · '.$site->radius_meters.' m' }}</flux:select.option>
                @endforeach
            </flux:select>
        @elseif ($type === 'select')
            <flux:select
                wire:model.live="form.{{ $key }}"
                aria-label="{{ $field['label'] }}"
                class="{{ $error ? 'border-danger!' : '' }}"
            >
                <flux:select.option value="" disabled hidden>Not set</flux:select.option>
                @foreach ($field['options'] ?? [] as $option)
                    <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
        @elseif ($type === 'textarea')
            <flux:textarea
                rows="2"
                wire:model.blur="form.{{ $key }}"
                aria-label="{{ $field['label'] }}"
                aria-invalid="{{ $error ? 'true' : 'false' }}"
                class="{{ $error ? 'border-danger!' : '' }}"
            />
        @else
            <flux:input
                type="{{ $type === 'number' ? 'number' : ($type ?? 'text') }}"
                :min="$type === 'number' ? '0' : null"
                :step="$type === 'number' ? '0.01' : null"
                wire:model.blur="form.{{ $key }}"
                aria-label="{{ $field['label'] }}"
                aria-invalid="{{ $error ? 'true' : 'false' }}"
                class:input="{{ trim(($type === 'number' ? 'num ' : '').($error ? 'border-danger!' : '')) }}"
            />
        @endif

        @if ($error)
            <p class="text-xs text-danger">{{ $error }}</p>
        @endif
    </div>
@endif
