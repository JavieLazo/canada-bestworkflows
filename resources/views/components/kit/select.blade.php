{{--
    The system's dropdown: the same tag shape as <flux:select>, drawn the way
    the employee picker is (a trigger styled as a field, and a panel with the
    list, a check on the chosen option and a search box once the list is long)
    instead of the browser's own select list.

    A HIDDEN NATIVE <select> carries every binding, so callers keep writing
    what they wrote for flux:select. `wire:model` (with `.live`),
    `wire:change="…($event.target.value)"`, `x-model`, `x-on:change`, `name`
    and `required` land on it, its options are the slot (`<flux:select.option>`
    tags, a `<hr>` for a rule, `:selected` honoured), and Livewire keeps
    morphing it. A pick writes to it and fires the same `input` and `change` a
    native pick fires. resources/js/kit-select.js (`kitSelect`) holds the
    behaviour and why. `class`, `style` and `wire:key` go on the outer box,
    which is what a width class such as `w-[10rem]` should size. `id` and
    `aria-*` go on the trigger, the element that takes focus.

    Before the scripts run, the trigger shows the option the browser would
    show (App\Livewire\Kit\Support\SelectSlot), so a page still arriving
    already reads right.

    The panel is a native `popover="manual"` that stays a DOM descendant of the
    field, like the date-field calendar and the employee picker, so it escapes
    `overflow-hidden` cards and Flux dialogs without going inert under a modal.
    It is `wire:ignore`: Alpine draws the list from the native options each
    time it opens.

    Usage:
        <x-kit.select wire:model.live="status" aria-label="Status">
            <flux:select.option value="ACTIVE">Active</flux:select.option>
            <flux:select.option value="INACTIVE">Inactive</flux:select.option>
        </x-kit.select>

        <x-kit.select label="Shift" wire:model="dateShiftChoice" placeholder="Select shift" size="sm">…</x-kit.select>

    Props: `label` (a visible Flux label, with the field's validation
    message), `placeholder`, `size` (sm | xs), `invalid` (defaults to an
    error on the bound name), `disabled`, `searchable` (null = only past
    eight options), `clearValue` (shows a clear button whenever the value is
    not this one, which is what <x-kit.filter-select> uses for its "all" row).
--}}
@props([
    'label' => null,
    'placeholder' => null,
    'size' => null,
    'invalid' => null,
    'disabled' => false,
    'searchable' => null,
    'clearValue' => null,
])
@php
    // Bindings belong to the hidden select. The prefixes cover the modifier
    // forms (`wire:model.live`, `x-model.number`, `x-on:change.debounce`).
    $isBinding = static fn (string $key): bool => \Illuminate\Support\Str::startsWith($key, [
        'wire:model', 'wire:change', 'wire:input', 'x-model', 'x-on:change', 'x-on:input', '@change', '@input',
    ]) || in_array($key, ['name', 'required', 'form'], true);

    $bindings = $attributes->filter(fn ($value, $key) => $isBinding($key));
    $triggerAttributes = $attributes->filter(fn ($value, $key) => ! $isBinding($key) && ($key === 'id' || $key === 'autofocus' || (str_starts_with($key, 'aria-') && $key !== 'aria-label')));
    $rootAttributes = $attributes->filter(fn ($value, $key) => ! $isBinding($key) && ! $triggerAttributes->has($key) && $key !== 'aria-label');

    $model = $attributes->wire('model');
    $modelPath = $model?->directive ? $model->value() : null;
    $xModel = collect($bindings->getAttributes())->first(fn ($value, $key) => str_starts_with((string) $key, 'x-model'));
    $name = $attributes->get('name') ?? $modelPath;
    $invalid ??= $name !== null && isset($errors) && $errors->has($name);
    $accessibleName = (string) ($attributes->get('aria-label') ?? $label ?? '');

    $placeholderOption = $placeholder !== null
        ? '<option value="" disabled selected class="placeholder">'.e($placeholder).'</option>'
        : '';
    $first = \App\Livewire\Kit\Support\SelectSlot::initial($placeholderOption.$slot->toHtml());

    $config = [
        'searchable' => $searchable,
        'clearValue' => $clearValue,
        'model' => $modelPath,
        'xModel' => $xModel,
    ];

    $sizeClasses = match ($size) {
        'sm' => 'h-8 gap-1.5 rounded-md px-2.5 text-sm',
        'xs' => 'h-6 gap-1 rounded-md px-2 text-xs',
        default => 'h-10 gap-2 rounded-lg px-3 text-sm',
    };
@endphp
<div
    {{ $rootAttributes->class('relative [:where(&)]:w-full') }}
    x-data="kitSelect({{ \Illuminate\Support\Js::from($config) }})"
    x-id="['kit-select-name', 'kit-select-value', 'kit-select-list', 'kit-select-option', 'kit-select-group']"
    x-on:focusout="onFocusOut($event)"
    x-on:click.outside="close()"
    data-kit-select
>
    {{-- The bindings' element. Out of the tab order and the accessibility
         tree: the trigger below is the control people use. It carries the
         same aria-label, so the options read from the markup sit under the
         name they belong to. --}}
    <select x-ref="native" @if ($accessibleName !== '') aria-label="{{ $accessibleName }}" @endif {{ $bindings }} @disabled($disabled) tabindex="-1" aria-hidden="true" class="sr-only">{!! $placeholderOption !!}{{ $slot }}</select>

    {{-- The trigger's accessible name; the trigger reads it, then its value.
         `hidden`: aria-labelledby still reads it, and a screen reader in
         browse mode does not read it a second time as page text. --}}
    <span x-ref="name" x-bind:id="$id('kit-select-name')" hidden x-text="@js($accessibleName) || externalName">{{ $accessibleName }}</span>

    <flux:with-field :attributes="new \Illuminate\View\ComponentAttributeBag(array_filter(['label' => $label, 'name' => $name], fn ($v) => $v !== null))">
        {{-- The trigger's tabindex="0" is explicit: Safari leaves a button
             out of the Tab order unless "Press Tab to highlight each item" is
             on, and the native select this replaces was always in it. --}}
        <div class="relative">
            <button
                x-ref="trigger"
                {{ $triggerAttributes }}
                type="button"
                tabindex="0"
                role="combobox"
                aria-haspopup="listbox"
                @if ($accessibleName !== '') aria-label="{{ $accessibleName }}" @endif
                x-bind:aria-expanded="open ? 'true' : 'false'"
                x-bind:aria-controls="$id('kit-select-list')"
                x-bind:aria-labelledby="(@js($accessibleName) || externalName ? $id('kit-select-name') + ' ' : '') + $id('kit-select-value')"
                @disabled($disabled)
                @if ($invalid) aria-invalid="true" data-invalid @endif
                data-flux-control
                x-on:click="toggle()"
                x-on:keydown="onTriggerKeydown($event)"
                @class([
                    'flex w-full cursor-pointer items-center justify-between whitespace-nowrap border bg-transparent text-left shadow-xs',
                    'focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                    $sizeClasses,
                    $invalid ? 'border-danger' : 'border-input',
                ])
            >
                <span
                    x-bind:id="$id('kit-select-value')"
                    x-text="label"
                    x-bind:class="{ 'text-muted-foreground': placeholder, 'font-medium': clearable }"
                    data-kit-select-value
                    data-placeholder="{{ $first['placeholder'] ? 'true' : 'false' }}"
                    @class(['min-w-0 truncate', 'text-muted-foreground' => $first['placeholder']])
                >{{ $first['label'] }}</span>
                {{-- Invisible, not removed, while the clear button sits over it:
                     the room it keeps stops a long value short of the button. --}}
                <flux:icon.chevron-down x-bind:class="{ 'invisible': clearable }" class="size-4 shrink-0 opacity-50" />
            </button>

            @if ($clearValue !== null)
                {{-- A sibling laid over the trigger, never inside it: a button
                     cannot contain another button. --}}
                <button
                    type="button"
                    x-cloak
                    x-show="clearable"
                    x-on:click="clear()"
                    aria-label="Clear {{ \Illuminate\Support\Str::lower($accessibleName) }}"
                    class="absolute right-2 top-1/2 z-10 -translate-y-1/2 cursor-pointer rounded-sm p-0.5 text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                >
                    <flux:icon.x-mark class="size-4" />
                </button>
            @endif
        </div>
    </flux:with-field>

    {{-- The panel. The UA popover stylesheet is overridden explicitly (m-0,
         inset-auto, p-0, the border, colours and size) because it sets
         margin:auto, inset:0, a solid border, padding, Canvas colours and
         fit-content; its display:none while not :popover-open is the hidden
         state in a supporting browser, and x-show is the fallback's.
         `font-sans` because a caller's `num` on the box would otherwise
         reach the top layer. A mousedown inside keeps focus where it is
         (the search box or the list), so a click never reads as focus
         leaving the field. --}}
    <div
        x-ref="panel"
        popover="manual"
        wire:ignore
        x-cloak
        x-show="native || open"
        x-bind:class="native ? 'fixed inset-auto w-max' : 'absolute left-0 top-full mt-1 min-w-full'"
        x-on:toggle="onToggle($event)"
        x-on:keydown="onPanelKeydown($event)"
        x-on:mousedown="if ($event.target !== $refs.search) $event.preventDefault()"
        class="z-50 m-0 h-auto overflow-hidden rounded-md border border-border bg-popover p-0 font-sans text-sm font-normal text-popover-foreground shadow-md"
    >
        <template x-if="searching">
            <div class="relative border-b border-border">
                <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                {{-- While it is there, the search box is the combobox a screen
                     reader tracks, and it carries the highlight. --}}
                <input
                    x-ref="search"
                    type="text"
                    role="combobox"
                    aria-expanded="true"
                    aria-autocomplete="list"
                    x-bind:aria-controls="$id('kit-select-list')"
                    x-bind:aria-activedescendant="activeId()"
                    x-bind:aria-label="'Search ' + (@js($accessibleName) || externalName || 'options').toLowerCase()"
                    placeholder="Search"
                    autocomplete="off"
                    x-model="query"
                    x-on:input="onSearchInput()"
                    class="h-10 w-full border-0 bg-transparent pl-9 pr-3 text-base outline-none placeholder:text-muted-foreground sm:text-sm"
                />
            </div>
        </template>

        <div
            x-ref="list"
            x-bind:id="$id('kit-select-list')"
            role="listbox"
            x-bind:aria-labelledby="@js($accessibleName) || externalName ? $id('kit-select-name') : null"
            x-bind:tabindex="searching ? null : -1"
            x-bind:aria-activedescendant="searching ? null : activeId()"
            class="max-h-72 overflow-y-auto p-1 outline-none"
        >
            <p aria-live="polite" class="sr-only" x-text="status()"></p>

            {{-- An <optgroup> is a role="group" section named by its heading;
                 options outside any group sit in an unnamed run. The role="none"
                 wrappers drop out of the accessibility tree, so each option is a
                 child of its group or of the listbox. --}}
            <template x-for="section in sections()" :key="section.key">
                <div
                    x-bind:role="section.group ? 'group' : 'none'"
                    x-bind:aria-labelledby="section.group ? $id('kit-select-group') + '-' + section.group : null"
                >
                    <template x-if="section.group">
                        <div
                            x-bind:id="$id('kit-select-group') + '-' + section.group"
                            role="presentation"
                            class="px-2 pb-1 pt-2 text-xs font-medium text-muted-foreground"
                            x-text="section.label"
                        ></div>
                    </template>
                    <template x-for="row in section.rows" :key="row.key">
                        <div role="none">
                            <template x-if="row.type === 'option'">
                                <div
                                    role="option"
                                    x-bind:id="optionId(row)"
                                    x-bind:aria-selected="isSelected(row) ? 'true' : 'false'"
                                    x-bind:aria-disabled="row.disabled ? 'true' : null"
                                    x-bind:data-active="row.key === activeKey ? 'true' : null"
                                    x-bind:class="{
                                        'bg-accent text-accent-foreground': row.key === activeKey,
                                        'cursor-pointer': ! row.disabled,
                                        'cursor-not-allowed opacity-50': row.disabled,
                                    }"
                                    x-on:mouseenter="if (! row.disabled) activeKey = row.key"
                                    x-on:click="choose(row)"
                                    class="flex w-full items-center gap-3 rounded-sm px-2 py-1.5 text-left"
                                >
                                    <span class="min-w-0 flex-1 break-words" x-text="row.label"></span>
                                    <span class="flex size-4 shrink-0 items-center justify-center">
                                        <flux:icon.check x-show="isSelected(row)" class="size-4" />
                                    </span>
                                </div>
                            </template>
                            {{-- A drawn rule only: a listbox may not hold a separator. --}}
                            <template x-if="row.type === 'separator'">
                                <div role="none" aria-hidden="true" class="-mx-1 my-1 h-px bg-border"></div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>

            <p x-show="visible().length === 0" class="px-3 py-6 text-center text-sm text-muted-foreground">
                <span x-text="searching && query.trim() !== '' ? 'Nothing matches that search.' : 'Nothing to choose.'"></span>
            </p>
        </div>
    </div>
</div>
