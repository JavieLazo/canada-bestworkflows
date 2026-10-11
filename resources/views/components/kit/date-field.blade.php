{{--
    Port of src/components/DateField.tsx — a date input that is typed first and
    picked second.

    Value in and out is the `YYYY-MM-DD` key the tables store, while what the
    user sees and types is `dd MMM yyyy` (`20 Aug 2026`, and `20/08/2026`,
    `2026-08-20`, `20 August 2026` all parse).
    Typing is authoritative: a half-finished entry is left alone until it
    parses, so the caret never jumps while someone is mid-word, and the text
    snaps to the canonical spelling on blur.

    THE PICKING HALF is a calendar popover of our own (the source's is Flux
    Pro's). The panel is a native `popover="manual"` element, which the
    browser renders in the TOP LAYER: the viewport is its containing
    block and an ancestor's overflow or transform does not apply, so it
    escapes an `hris-card overflow-hidden` and a Flux <dialog> (transformed,
    and scrolling in the projects dialogs) and stacks above an open modal.
    It STAYS A DESCENDANT OF THE FIELD: teleported to <body> it would be inert
    under a modal, and Flux would read a click on it as a click outside the
    dialog. `wire:ignore` keeps Livewire's morph off the Alpine-rendered grid
    and the inline position; the ROOT is not ignored, so `disabled` and
    `required` keep morphing. A browser without `showPopover` gets a plain
    absolute panel under the field, which those overflow containers clip —
    the accepted degradation. `font-sans` on the panel, because two callers
    pass `class="num"` (mono) to the root and the top layer still inherits.

    Behaviour and parsers live in resources/js/date-field.js (`dateField`,
    the JS mirror of App\Support\DateText — keep the two in sync, and run
    `npm run build` after touching it).

    Usage (inside a Livewire component):
        <x-kit.date-field wire:model="form.date_hired" aria-label="Date hired" />
--}}
@props([
    'id' => null,
    'required' => false,
    'disabled' => false,
    'ariaLabel' => null,
])
@php
    $model = $attributes->wire('model');

    if (! $model?->directive) {
        throw new \InvalidArgumentException('<x-kit.date-field> needs a wire:model binding.');
    }

    $entangle = '$wire.entangle('.\Illuminate\Support\Js::from($model->value()).')'.($model->hasModifier('live') ? '.live' : '');
    $ariaLabel ??= $attributes->get('aria-label') ?? 'Date';
    // A caller-supplied id names the panel deterministically (and testably);
    // otherwise Alpine's $id, stable across morphs because the root survives.
    $panelId = $id !== null ? $id.'-calendar' : null;
    // w-7, not w-8: the header is 278px wide (a w-[19rem] panel less its p-3
    // and border) and the Month dropdown needs 126px of it for "September".
    $panelControl = 'flex h-8 w-7 shrink-0 cursor-pointer items-center justify-center rounded-md transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
    $panelFooter = 'h-7 cursor-pointer rounded-md px-2 text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
@endphp
<div
    {{ $attributes->whereDoesntStartWith('wire:model')->except('aria-label')->class('relative') }}
    x-data="dateField({!! $entangle !!})"
    x-id="['kit-date']"
    x-on:keydown.escape="onEscape($event)"
    x-on:focusout="onFocusOut($event)"
>
    <flux:input
        type="text"
        :id="$id"
        :required="$required"
        :disabled="$disabled"
        aria-label="{{ $ariaLabel }}"
        placeholder="dd Mmm yyyy"
        autocomplete="off"
        class:input="pe-9!"
        x-bind:value="text"
        x-on:input="commit($event.target.value)"
        x-on:blur="settle()"
    />

    {{-- The calendar trigger, inside the input's right edge. --}}
    <button
        type="button"
        x-ref="trigger"
        @disabled($disabled)
        aria-label="Open calendar for {{ $ariaLabel }}"
        aria-haspopup="dialog"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        @if ($panelId) aria-controls="{{ $panelId }}" @else x-bind:aria-controls="$id('kit-date')" @endif
        x-on:click="toggle()"
        class="absolute right-1 top-1/2 flex h-7 w-7 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent"
    >
        <flux:icon.calendar-days class="size-4" />
    </button>

    {{--
        The panel. The UA popover stylesheet is overridden explicitly (m-0,
        inset-auto, the border, padding, colours and size) because it sets
        margin:auto, inset:0, a solid border, Canvas colours and fit-content;
        its `display:none` while not :popover-open is the hidden state in a
        supporting browser, and x-show is the fallback's.
    --}}
    <div
        x-ref="panel"
        @if ($panelId) id="{{ $panelId }}" @else x-bind:id="$id('kit-date')" @endif
        popover="manual"
        wire:ignore
        role="dialog"
        aria-label="Calendar for {{ $ariaLabel }}"
        x-cloak
        x-show="native || open"
        x-bind:class="native ? 'fixed inset-auto' : 'absolute right-0 top-full mt-1'"
        x-on:click.outside="close()"
        x-on:toggle="onToggle($event)"
        class="z-50 m-0 h-auto w-[19rem] max-h-[calc(100dvh-1rem)] overflow-y-auto rounded-md border border-border bg-popover p-3 font-sans text-popover-foreground shadow-lift transition-opacity duration-100 starting:opacity-0"
    >
        <p aria-live="polite" class="sr-only" x-text="viewLabel"></p>

        {{-- Header: previous · month · year · next --}}
        <div class="flex items-center gap-1">
            <button type="button" aria-label="Previous month" x-on:click="shiftMonth(-1)" class="{{ $panelControl }}">
                <flux:icon.chevron-left class="size-4" />
            </button>
            <x-kit.select size="sm" :searchable="false" aria-label="Month" x-model.number="view.month" x-on:change="viewChanged()" class="min-w-0 flex-1">
                @foreach (['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] as $i => $name)
                    <option value="{{ $i }}">{{ $name }}</option>
                @endforeach
            </x-kit.select>
            <x-kit.select size="sm" aria-label="Year" x-model.number="view.year" x-on:change="viewChanged()" class="w-[5.25rem] shrink-0 tabular-nums">
                <template x-for="y in years()" :key="y">
                    <option x-bind:value="y" x-text="y"></option>
                </template>
            </x-kit.select>
            <button type="button" aria-label="Next month" x-on:click="shiftMonth(1)" class="{{ $panelControl }}">
                <flux:icon.chevron-right class="size-4" />
            </button>
        </div>

        {{-- The grid: seven weekday headers, six rows of seven, one tabbable day (roving tabindex). --}}
        <table role="grid" aria-label="Days" class="mt-2 w-full border-collapse" x-on:keydown="onGridKeydown($event)">
            <thead>
                <tr>
                    @foreach (['Sunday' => 'Su', 'Monday' => 'Mo', 'Tuesday' => 'Tu', 'Wednesday' => 'We', 'Thursday' => 'Th', 'Friday' => 'Fr', 'Saturday' => 'Sa'] as $long => $short)
                        <th scope="col" abbr="{{ $long }}" class="h-8 w-8 text-center text-xs font-medium text-muted-foreground">{{ $short }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <template x-for="(week, w) in weeks" :key="w">
                    <tr>
                        <template x-for="d in week" :key="d.key">
                            <td role="gridcell" x-bind:aria-selected="d.key === state ? 'true' : 'false'" class="p-0 text-center">
                                <button
                                    type="button"
                                    x-bind:data-day="d.key"
                                    x-bind:tabindex="d.key === focusKey ? 0 : -1"
                                    x-bind:aria-label="d.label"
                                    x-bind:aria-current="d.today ? 'date' : null"
                                    x-on:click="select(d.key)"
                                    x-text="d.day"
                                    class="mx-auto flex h-8 w-8 cursor-pointer items-center justify-center rounded-md text-sm tabular-nums transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                    x-bind:class="{
                                        'text-muted-foreground': ! d.inMonth && d.key !== state,
                                        'border border-primary': d.today && d.key !== state,
                                        'bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground': d.key === state,
                                    }"
                                ></button>
                            </td>
                        </template>
                    </tr>
                </template>
            </tbody>
        </table>

        <div class="mt-2 flex items-center justify-between border-t border-border pt-2">
            <button type="button" x-on:click="pickToday()" class="{{ $panelFooter }}">Today</button>
            <button type="button" x-on:click="clear()" class="{{ $panelFooter }}">Clear</button>
        </div>
    </div>
</div>
