{{--
    The fields of one Personal Data Sheet list row (PdsLists::fields), in a
    `grid sm:grid-cols-2` parent: the record's add/edit dialog binds them to
    `form`, the hire wizard to `form.rows_<list>.<i>`.

    Each field is a label, its control, an optional hint and its first
    error. `<flux:label>` labels nothing outside a flux:field, so every
    control is named by its `aria-label` (the registry's `aria`, followed
    in the wizard by `$ariaSuffix`, such as " — school 2", because every
    row there repeats the labels). Years are a short numeric text input,
    dates the kit date field, amounts the kit money input.

    Params: $fields, $model (the wire:model path of the row), $fieldErrors
    (the row's field errors, keyed by field — never `errors`, which would
    shadow the shared error bag), $ariaSuffix (optional).
--}}
@foreach ($fields as $field)
    @php
        $key = $field['key'];
        $bind = $model.'.'.$key;
        $aria = $field['aria'].($ariaSuffix ?? '');
        $error = $fieldErrors[$key] ?? null;
    @endphp
    <div wire:key="pds-field-{{ $bind }}" class="space-y-2 {{ $field['span'] === 2 ? 'sm:col-span-2' : '' }}">
        <flux:label>{{ $field['label'] }}</flux:label>
        @if ($field['type'] === 'select')
            <x-kit.select wire:model="{{ $bind }}" aria-label="{{ $aria }}" :invalid="(bool) $error">
                {{-- A blank choice, unless the list brings its own: a pick made by mistake is cleared again. --}}
                @if (! in_array('', array_column($field['options'], 'value'), true))
                    <flux:select.option value="">Not set</flux:select.option>
                @endif
                @foreach ($field['options'] as $option)
                    <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </x-kit.select>
        @elseif ($field['type'] === 'date')
            <x-kit.date-field
                class="num {{ $error ? '[&_input]:border-danger!' : '' }}"
                wire:model="{{ $bind }}"
                aria-label="{{ $aria }}"
            />
        @elseif ($field['type'] === 'money')
            <x-kit.money-input
                wire:model="{{ $bind }}"
                :aria-label="$aria"
                :aria-invalid="(bool) $error"
                class="{{ $error ? 'border-danger!' : '' }}"
            />
        @elseif ($field['type'] === 'year')
            <flux:input
                wire:model="{{ $bind }}"
                aria-label="{{ $aria }}"
                placeholder="yyyy"
                inputmode="numeric"
                maxlength="4"
                aria-invalid="{{ $error ? 'true' : 'false' }}"
                class:input="num {{ $error ? 'border-danger!' : '' }}"
            />
        @elseif ($field['type'] === 'number')
            <flux:input
                wire:model="{{ $bind }}"
                aria-label="{{ $aria }}"
                inputmode="decimal"
                aria-invalid="{{ $error ? 'true' : 'false' }}"
                class:input="num {{ $error ? 'border-danger!' : '' }}"
            />
        @else
            <flux:input
                wire:model="{{ $bind }}"
                aria-label="{{ $aria }}"
                aria-invalid="{{ $error ? 'true' : 'false' }}"
                class:input="{{ $error ? 'border-danger!' : '' }}"
            />
        @endif
        @if ($field['hint'] !== null)
            <p class="text-xs text-muted-foreground">{{ $field['hint'] }}</p>
        @endif
        @if ($error)
            <p class="text-xs text-danger">{{ $error }}</p>
        @endif
    </div>
@endforeach
