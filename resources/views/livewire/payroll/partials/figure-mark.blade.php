{{-- Under a draft cell whose figure is not simply the company settings' (FigureSources): what it comes from, opening "Why this amount?". --}}
@php
    [$markTier, $markText] = \App\Livewire\Payroll\Support\FigureSources::LABELS[$source];
@endphp
<div class="mt-1 flex justify-end">
    <button
        type="button"
        wire:click="openWhy('{{ $employeeId }}', '{{ $cell }}')"
        aria-label="Why {{ $label }} is this amount ({{ $markText }})"
        class="pill {{ $markTier }} cursor-pointer hover:underline focus:outline-none focus:ring-2 focus:ring-ring"
        data-figure-mark="{{ $cell }}"
    >{{ $markText }}</button>
</div>
