{{--
    One employee field, read or edit — the port of `Field` in
    employee-fields.tsx:305-512.

    Expects:
        $field            the spec array (key, label, type?, options?, source?, site?, wide?, auto?, hint?, unit?,
                          metres?, inputmode?, legacy?, aria?, when?)
        $editing          bool
        $display          read-mode text (precomputed by the caller; ignored while editing)
        $formState        the Livewire form array (edit mode)
        $referenceOptions ['employment_statuses' => [...names]] — a `source` select's names
                          (Position, which carries `choice`, is drawn by partials/position-fields)
        $sites            collection of WorkSite (id, name, radius_meters, geofence_exempt, is_active)
        $employee         optional, inherited from the record page: an inactive site
                          is offered only while the record already names it
        $error            ?string — shown under the control, reddens its border
        $required         bool — asterisk on the label
        $legacy           optional string — the stored text a structured field
                          replaces (the citizenship), shown as "Entered before"
                          under the control while it is blank

    Spec keys this partial reads beyond the control type:
      - `aria`: the input's accessible name when the visible label alone is
        ambiguous (the address parts: "Residential street", "Permanent ZIP
        code"); the label otherwise;
      - `metres`: a number typed in metres (any decimals; EmployeeInput
        checks the shape and converts to the stored centimetres);
      - `inputmode`: forwarded to the input (the ZIP code's numeric keypad);
      - `when`: a field waiting on another answer. The CALLER decides whether
        to draw it (EmployeeFields::shown()); this partial draws what it is
        given.

    Flux-free approximations, noted per control:
      - the source's shadcn Select is the kit select. DELIBERATE DIVERGENCE:
        its first option is a blank "Not set" that CAN BE PICKED, so a
        choice made by mistake is cleared again; a required answer left
        blank is refused by the step's own check;
      - ManagedSelect's empty-list note renders as a disabled option;
      - the popover DateField is <x-kit.date-field> (typed input + calendar
        popover), per the shared kit.

    The `children` type (the Children card's list) has no counterpart in
    the source: edit mode draws a row per child — name, date of birth and
    remove — plus an "Add child" button while fewer than MAX_CHILDREN are
    listed. The rows bind `form.children.{i}.name` and
    `form.children.{i}.birth_date`; a phone wraps the date under the name.
    The component supplies addChild() and removeChild(i) (Show and
    AddEmployeeDialog both do). The record page reads the children as a
    table (partials/children), never through this partial's read mode.

    The `lines` type (the Other info lists) reads as one <dd> per line, or
    one "—", from the record ($employee). Edit mode draws an input per line
    with a remove button, then "Add" while fewer than MAX_ITEMS are listed;
    the component supplies addItem(key) and removeItem(key, i). A
    declaration's answer (`answer`) is drawn by partials/declarations, never
    here.
--}}
@php
    $key = (string) $field['key'];
    $type = $field['type'] ?? null;
    $wide = ! empty($field['wide']) ? 'sm:col-span-2' : '';
    $current = $formState[$key] ?? '';
    $aria = (string) ($field['aria'] ?? $field['label']);
    $legacyText = isset($legacy) && is_string($legacy) ? $legacy : '';
@endphp
@if (! $editing && $type === 'lines')
    @php $readLines = isset($employee) ? \App\Livewire\Employees\EmployeeFields::itemLines($employee, $key) : []; @endphp
    <div class="min-w-0 {{ $wide }}">
        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $field['label'] }}</dt>
        @forelse ($readLines as $line)
            <dd class="mt-1 break-words text-sm">{{ $line }}</dd>
        @empty
            <dd class="mt-1 break-words text-sm">—</dd>
        @endforelse
    </div>
@elseif (! $editing)
    <div class="min-w-0 {{ $wide }}">
        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $field['label'] }}</dt>
        <dd class="mt-1 break-words text-sm {{ \App\Livewire\Employees\EmployeeFields::isNumFace($field) ? 'num' : '' }} {{ $type === 'children' ? 'whitespace-pre-line' : '' }}">{{ $display }}</dd>
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
                 reads as filled-in rather than fillable. NO SECTION CARRIES
                 `auto` TODAY — the minted row id is not a Personal Details
                 field — and this branch stays as the twin of
                 UpdateEmployee's: the draft round-trips whatever the client
                 sends, so the command drops every `auto` key from the payload
                 rather than trusting this markup to be the only way in. --}}
            <flux:input disabled class:input="num" value="{{ $current }}" aria-label="{{ $aria }}" />
            <p class="text-xs text-muted-foreground">Assigned automatically.</p>
        @elseif ($type === 'date')
            <x-kit.date-field
                wire:model="form.{{ $key }}"
                aria-label="{{ $aria }}"
                class="{{ $error ? '[&_input]:border-danger!' : '' }}"
            />
        @elseif ($type === 'money')
            <x-kit.money-input
                wire:model="form.{{ $key }}"
                :aria-label="$aria"
                :aria-invalid="(bool) $error"
                class="{{ $error ? 'border-danger!' : '' }}"
            />
        @elseif ($type === 'switch')
            {{-- Boolean on the record, Yes/No in the UI (employee-fields.tsx:398-409). --}}
            <x-kit.select wire:model.live="form.{{ $key }}" aria-label="{{ $aria }}">
                <flux:select.option value="true" :selected="\App\Livewire\Employees\EmployeeFields::asBool($current)">Yes</flux:select.option>
                <flux:select.option value="false" :selected="! \App\Livewire\Employees\EmployeeFields::asBool($current)">No</flux:select.option>
            </x-kit.select>
        @elseif (isset($field['source']))
            @php
                $names = $referenceOptions[$field['source']] ?? [];
                if ($current !== '' && ! in_array($current, $names, true)) {
                    // The value already on the record is always offered, even
                    // once it is off the list (ManagedSelect, employee-fields.tsx:262-303).
                    array_unshift($names, $current);
                }
            @endphp
            <x-kit.select
                wire:model.live="form.{{ $key }}"
                aria-label="{{ $aria }}"
                :invalid="(bool) $error"
            >
                <flux:select.option value="">Not set</flux:select.option>
                @if ($names === [])
                    <flux:select.option value="" disabled>Nothing on this list yet — add it in Records Management.</flux:select.option>
                @endif
                @foreach ($names as $name)
                    <flux:select.option value="{{ $name }}">{{ $name }}</flux:select.option>
                @endforeach
            </x-kit.select>
        @elseif (! empty($field['site']))
            <x-kit.select
                wire:model.live="form.{{ $key }}"
                aria-label="{{ $aria }}"
                :invalid="(bool) $error"
            >
                <flux:select.option value="">No assigned worksite</flux:select.option>
                @foreach ($sites as $site)
                    @continue(! $site->is_active && (! isset($employee) || $employee->work_site_id !== $site->id))
                    <flux:select.option value="{{ $site->id }}">{{ $site->name }}{{ $site->geofence_exempt ? ' · location not checked' : ' · '.$site->radius_meters.' m' }}{{ $site->is_active ? '' : ' · inactive' }}</flux:select.option>
                @endforeach
            </x-kit.select>
            {{-- The fallback for every weekday the Shift Roster's "Weekly
                 locations" leaves at "Assigned worksite". --}}
            <p class="text-xs text-muted-foreground">Used every day unless the Shift Roster sets a different location for a weekday.</p>
        @elseif ($type === 'select')
            <x-kit.select
                wire:model.live="form.{{ $key }}"
                aria-label="{{ $aria }}"
                :invalid="(bool) $error"
            >
                <flux:select.option value="">Not set</flux:select.option>
                @foreach ($field['options'] ?? [] as $option)
                    <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </x-kit.select>
        @elseif ($type === 'children')
            @php $children = is_array($current) ? $current : []; @endphp
            <div class="space-y-2">
                @forelse ($children as $i => $child)
                    <div wire:key="child-row-{{ $i }}" class="flex flex-wrap items-start gap-2">
                        <flux:input
                            class="min-w-0 flex-1"
                            wire:model.blur="form.{{ $key }}.{{ $i }}.name"
                            placeholder="Child's name"
                            aria-label="Child {{ $i + 1 }} name"
                        />
                        <x-kit.date-field
                            class="num w-40 shrink-0"
                            wire:model="form.{{ $key }}.{{ $i }}.birth_date"
                            aria-label="Child {{ $i + 1 }} date of birth"
                        />
                        <flux:button
                            class="shrink-0"
                            variant="ghost"
                            size="sm"
                            icon="x-mark"
                            wire:click="removeChild({{ $i }})"
                            aria-label="Remove child {{ $i + 1 }}"
                        />
                    </div>
                @empty
                    <p class="text-xs text-muted-foreground">No children listed.</p>
                @endforelse
                @if (count($children) < \App\Livewire\Employees\EmployeeFields::MAX_CHILDREN)
                    <flux:button variant="outline" size="sm" icon="plus" wire:click="addChild">
                        Add Child
                    </flux:button>
                @endif
            </div>
        @elseif ($type === 'lines')
            @php $lines = is_array($current) ? $current : []; @endphp
            <div class="space-y-2">
                @forelse ($lines as $i => $line)
                    <div wire:key="line-{{ $key }}-{{ $i }}" class="flex items-start gap-2">
                        <flux:input
                            class="min-w-0 flex-1"
                            wire:model.blur="form.{{ $key }}.{{ $i }}"
                            aria-label="{{ $field['label'] }} {{ $i + 1 }}"
                        />
                        <flux:button
                            class="shrink-0"
                            variant="ghost"
                            size="sm"
                            icon="x-mark"
                            wire:click="removeItem('{{ $key }}', {{ $i }})"
                            aria-label="Remove {{ $field['label'] }} {{ $i + 1 }}"
                        />
                    </div>
                @empty
                    <p class="text-xs text-muted-foreground">No lines listed.</p>
                @endforelse
                @if (count($lines) < \App\Livewire\Employees\EmployeeFields::MAX_ITEMS)
                    <flux:button variant="outline" size="sm" icon="plus" wire:click="addItem('{{ $key }}')">
                        Add
                    </flux:button>
                @endif
            </div>
        @elseif ($type === 'textarea')
            <flux:textarea
                rows="2"
                wire:model.blur="form.{{ $key }}"
                aria-label="{{ $aria }}"
                aria-invalid="{{ $error ? 'true' : 'false' }}"
                class="{{ $error ? 'border-danger!' : '' }}"
            />
        @else
            <flux:input
                type="{{ $type === 'number' ? 'number' : ($type ?? 'text') }}"
                :min="$type === 'number' ? '0' : null"
                :step="! empty($field['metres']) ? 'any' : ($type === 'number' ? '0.01' : null)"
                :inputmode="$field['inputmode'] ?? null"
                wire:model.blur="form.{{ $key }}"
                aria-label="{{ $aria }}"
                aria-invalid="{{ $error ? 'true' : 'false' }}"
                class:input="{{ trim(($type === 'number' ? 'num ' : '').($error ? 'border-danger!' : '')) }}"
            />
        @endif

        @if (! empty($field['hint']))
            <p class="text-xs text-muted-foreground">{{ $field['hint'] }}</p>
        @endif

        @if ($legacyText !== '' && ($current === '' || $current === null))
            <p class="text-xs text-muted-foreground">Entered before: {{ $legacyText }} — shown until a citizenship is chosen.</p>
        @endif

        @if ($error)
            <p class="text-xs text-danger">{{ $error }}</p>
        @endif
    </div>
@endif
