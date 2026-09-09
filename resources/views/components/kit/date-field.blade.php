{{--
    Port of src/components/DateField.tsx — a date input that is typed first and
    picked second.

    Value in and out stays the `YYYY-MM-DD` key the tables store — callers are
    unchanged by the swap — while what the user sees and types is `dd MMM yyyy`
    (`20 Aug 2026`, and `20/08/2026`, `2026-08-20`, `20 August 2026` all parse).
    Typing is authoritative: a half-finished entry is left alone until it
    parses, so the caret never jumps while someone is mid-word, and the text
    snaps to the canonical spelling on blur.

    The source's popover calendar is Flux Pro, so the picking half degrades to
    the BROWSER'S OWN date picker: the calendar icon is a transparent native
    `type="date"` input laid over it — clicking where the icon sits opens the
    native picker, and a pick writes the same key typing would have.

    The parsing functions are a JS mirror of App\Livewire\Kit\DateText — keep
    the two in sync.

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
@endphp
<div
    {{ $attributes->whereDoesntStartWith('wire:model')->except('aria-label')->class('relative') }}
    x-data="{
        state: {!! $entangle !!},
        text: '',
        MONTHS: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        toDisplay(key) {
            const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(key).trim());
            if (!m) return '';
            const month = this.MONTHS[Number(m[2]) - 1];
            return month ? m[3] + ' ' + month + ' ' + m[1] : '';
        },
        toKey(text) {
            const raw = String(text).trim();
            if (!raw) return '';
            const iso = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(raw);
            const slashed = /^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/.exec(raw);
            const worded = /^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/.exec(raw);
            let year, month, day;
            if (iso) { year = Number(iso[1]); month = Number(iso[2]); day = Number(iso[3]); }
            else if (slashed) { day = Number(slashed[1]); month = Number(slashed[2]); year = Number(slashed[3]); }
            else if (worded) {
                const idx = this.MONTHS.findIndex((mm) => worded[2].toLowerCase().startsWith(mm.toLowerCase()));
                if (idx < 0) return '';
                day = Number(worded[1]); month = idx + 1; year = Number(worded[3]);
            } else return '';
            if (month < 1 || month > 12 || day < 1 || day > 31) return '';
            const date = new Date(year, month - 1, day);
            if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return '';
            return String(year).padStart(4, '0') + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        },
        init() {
            if (this.state === null || this.state === undefined) this.state = '';
            this.text = this.toDisplay(this.state);
            {{-- Re-sync when the form is reset or the value changes from outside, but
                 not while the field holds an equivalent value — that would fight the
                 typist (DateField.tsx:95-98). --}}
            this.$watch('state', (v) => { if (this.toKey(this.text) !== v) this.text = this.toDisplay(v ?? ''); });
        },
        commit(next) {
            this.text = next;
            const key = this.toKey(next);
            if (!next.trim()) this.state = '';
            else if (key) this.state = key;
        },
        settle() { this.text = this.toDisplay(this.state ?? ''); },
        pick(v) { if (!v) return; this.state = v; this.text = this.toDisplay(v); }
    }"
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

    {{-- The calendar affordance: a decorative icon with the browser's own date
         input laid transparently over it. --}}
    <span class="pointer-events-none absolute right-1 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center text-muted-foreground">
        <flux:icon.calendar-days class="size-4" />
    </span>
    <input
        type="date"
        tabindex="-1"
        aria-hidden="true"
        @disabled($disabled)
        class="absolute right-1 top-1/2 h-7 w-7 -translate-y-1/2 cursor-pointer opacity-0"
        x-bind:value="state"
        x-on:click="try { $event.target.showPicker() } catch (e) {}"
        x-on:change="pick($event.target.value)"
    />
</div>
