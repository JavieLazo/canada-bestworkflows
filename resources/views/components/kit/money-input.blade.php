{{--
    Port of src/components/MoneyInput.tsx — an amount-of-money input that
    formats itself while it is typed.

    `1000` reads as `1,000` the moment the fourth digit lands and settles as
    `1,000.00` on blur, so every peso figure in a form looks like the peso
    figures in the tables. The bound value stays a plain numeric string
    (`"1000"`, `"1000.50"`) — `(float) $value` in the callers keeps working. A
    keystroke that would not read as an amount (letters, a second dot, a third
    decimal) is dropped and the caret stays put; a leftover that never became a
    number (a lone ".") clears on blur rather than settling as "NaN". Right
    aligned, tabular numerals.

    Usage (inside a Livewire component):
        <x-kit.money-input wire:model="form.monthly_rate" aria-label="Monthly rate" />

    `placeholder` says what a BLANK field means where blank is not zero
    ("From the table"); left out, it reads 0.00.
--}}
@props([
    'id' => null,
    'disabled' => false,
    'placeholder' => '0.00',
    'ariaLabel' => null,
    'ariaInvalid' => null,
])
@php
    $model = $attributes->wire('model');

    if (! $model?->directive) {
        throw new \InvalidArgumentException('<x-kit.money-input> needs a wire:model binding.');
    }

    $entangle = '$wire.entangle('.\Illuminate\Support\Js::from($model->value()).')'.($model->hasModifier('live') ? '.live' : '');
    $ariaLabel ??= $attributes->get('aria-label');
    // The caller's class styles the input (its Flux wrapper), as the source
    // put className on the <Input> itself; this outer div is layout-neutral.
    $class = $attributes->get('class');
@endphp
<div
    {{ $attributes->whereDoesntStartWith('wire:model')->except(['aria-label', 'class'])->class('contents') }}
    x-data="{
        state: {!! $entangle !!},
        text: '',
        group(int) { return int.replace(/\B(?=(\d{3})+(?!\d))/g, ','); },
        toDisplay(raw) {
            if (!raw) return '';
            const [int = '', dec] = String(raw).split('.');
            return dec === undefined ? this.group(int) : this.group(int) + '.' + dec;
        },
        toRaw(text) {
            const cleaned = String(text).replace(/,/g, '');
            return /^\d*(\.\d{0,2})?$/.test(cleaned) ? cleaned : null;
        },
        init() {
            if (this.state === null || this.state === undefined) this.state = '';
            this.state = String(this.state);
            this.text = this.toDisplay(this.state);
            {{-- Re-sync on outside changes, but not while the field already holds
                 the same amount — that would fight the typist (MoneyInput.tsx:57-62). --}}
            this.$watch('state', (v) => { if (this.toRaw(this.text) !== v) this.text = this.toDisplay(v ?? ''); });
        },
        onInput(e) {
            const typed = e.target.value;
            const raw = this.toRaw(typed);
            {{-- MoneyInput.tsx:64-77: an unacceptable keystroke restores what the
                 field had, with the caret where it was. --}}
            if (raw === null) {
                const el = e.target;
                const back = typed.length - (el.selectionStart ?? typed.length);
                el.value = this.text;
                const at = Math.max(0, this.text.length - back);
                el.setSelectionRange(at, at);
                return;
            }
            const caret = e.target.selectionStart ?? typed.length;
            {{-- Count the digits (and dot) left of the caret; commas move around them. --}}
            const significant = typed.slice(0, caret).replace(/,/g, '').length;
            const display = this.toDisplay(raw);
            this.text = display;
            this.state = raw;
            requestAnimationFrame(() => {
                const el = e.target;
                if (!el.isConnected) return;
                el.value = display;
                let seen = 0;
                let at = 0;
                while (at < display.length && seen < significant) {
                    if (display[at] !== ',') seen += 1;
                    at += 1;
                }
                el.setSelectionRange(at, at);
            });
        },
        settle() {
            {{-- MoneyInput.tsx:103-116: `1,000` settles as `1,000.00`, and the
                 stored value follows so the form and its payload can never
                 disagree; a leftover that never became a number clears. --}}
            if (this.state !== '') {
                const n = Number(this.state);
                if (!Number.isFinite(n)) {
                    this.text = '';
                    this.state = '';
                } else {
                    const settled = n.toFixed(2);
                    this.text = this.toDisplay(settled);
                    if (settled !== this.state) this.state = settled;
                }
            }
        }
    }"
>
    <flux:input
        type="text"
        :id="$id"
        :disabled="$disabled"
        inputmode="decimal"
        autocomplete="off"
        :placeholder="$placeholder"
        :aria-label="$ariaLabel"
        :aria-invalid="$ariaInvalid ? 'true' : null"
        :class="$class"
        class:input="num text-right"
        x-bind:value="text"
        x-on:input="onInput($event)"
        x-on:blur="settle()"
        {{-- A settled amount ends in ".00", so a caret dropped at the end would
             have every typed digit rejected as a third decimal. Selecting the
             amount on focus makes typing replace it (MoneyInput.tsx:133-136). --}}
        x-on:focus="$event.target.select()"
    />
</div>
