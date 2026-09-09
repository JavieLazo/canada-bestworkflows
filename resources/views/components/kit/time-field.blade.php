{{--
    A clock-time input in the 12-hour clock the rest of the system speaks —
    the port of src/components/TimeField.tsx reshaped by the locked product
    decision: a TYPED clock box plus a SEPARATE AM/PM select. No popover
    pickers of any kind.

    Value in and out stays the `HH:mm` (24-hour) key the tables store —
    callers are unchanged — while what the user types is `8:30` (or `830`, or
    a bare `8`), and AM/PM is its own select. A change to one part keeps the
    other; picking a period while the field is empty fills in the
    workday-shaped 8:00 default so one pick produces a complete time
    (TimeField.tsx:62-66). A half-typed clock is left alone until it parses;
    blur snaps it to the canonical spelling.

    The parsing functions are a JS mirror of App\Livewire\Kit\TimeText — keep
    the two in sync.

    Usage (inside a Livewire component):
        <x-kit.time-field wire:model="form.time_in" aria-label="Time in" />
--}}
@props([
    'id' => null,
    'disabled' => false,
    'ariaLabel' => null,
])
@php
    $model = $attributes->wire('model');

    if (! $model?->directive) {
        throw new \InvalidArgumentException('<x-kit.time-field> needs a wire:model binding.');
    }

    $entangle = '$wire.entangle('.\Illuminate\Support\Js::from($model->value()).')'.($model->hasModifier('live') ? '.live' : '');
    $ariaLabel ??= $attributes->get('aria-label') ?? 'Time';
@endphp
<div
    {{ $attributes->whereDoesntStartWith('wire:model')->except('aria-label')->class('flex items-center gap-1') }}
    x-data="{
        state: {!! $entangle !!},
        clock: '',
        period: 'AM',
        parts(value) {
            const m = /^(\d{1,2}):(\d{2})/.exec(String(value).trim());
            if (!m) return null;
            const h24 = Number(m[1]);
            if (h24 > 23) return null;
            return {
                clock: String(h24 % 12 === 0 ? 12 : h24 % 12) + ':' + m[2],
                period: h24 < 12 ? 'AM' : 'PM',
            };
        },
        toKey(clock, period) {
            const raw = String(clock).trim();
            if (!raw) return '';
            let hour, minute;
            const colon = /^(\d{1,2}):(\d{2})$/.exec(raw);
            const digits = /^(\d{1,2})(\d{2})$/.exec(raw);
            const bare = /^(\d{1,2})$/.exec(raw);
            if (colon) { hour = Number(colon[1]); minute = Number(colon[2]); }
            else if (digits) { hour = Number(digits[1]); minute = Number(digits[2]); }
            else if (bare) { hour = Number(bare[1]); minute = 0; }
            else return '';
            if (hour < 1 || hour > 12 || minute > 59) return '';
            let h24 = hour % 12;
            if (period === 'PM') h24 += 12;
            return String(h24).padStart(2, '0') + ':' + String(minute).padStart(2, '0');
        },
        init() {
            if (this.state === null || this.state === undefined) this.state = '';
            this.sync();
            this.$watch('state', () => { if (this.toKey(this.clock, this.period) !== this.state) this.sync(); });
        },
        sync() {
            const p = this.parts(this.state ?? '');
            this.clock = p ? p.clock : '';
            this.period = p ? p.period : 'AM';
        },
        setClock(next) {
            this.clock = next;
            if (!next.trim()) { this.state = ''; return; }
            const key = this.toKey(next, this.period);
            if (key) this.state = key;
        },
        setPeriod(next) {
            this.period = next;
            if (!this.clock.trim()) {
                this.clock = '8:00';
                this.state = this.toKey('8:00', next);
                return;
            }
            const key = this.toKey(this.clock, next);
            if (key) this.state = key;
        },
        settle() { this.sync(); }
    }"
>
    <flux:input
        type="text"
        :id="$id"
        :disabled="$disabled"
        aria-label="{{ $ariaLabel }} — time"
        placeholder="hh:mm"
        autocomplete="off"
        inputmode="numeric"
        class="w-20!"
        class:input="num"
        x-bind:value="clock"
        x-on:input="setClock($event.target.value)"
        x-on:blur="settle()"
    />
    <flux:select
        :disabled="$disabled"
        aria-label="{{ $ariaLabel }} — AM or PM"
        class="w-[4.5rem]"
        x-model="period"
        x-on:change="setPeriod($event.target.value)"
    >
        <flux:select.option value="AM">AM</flux:select.option>
        <flux:select.option value="PM">PM</flux:select.option>
    </flux:select>
</div>
