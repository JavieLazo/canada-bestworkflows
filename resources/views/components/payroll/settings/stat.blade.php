{{--
    One figure above a Payroll Settings list, drawn as the Daily Time
    Record's summary cards are: a small label, the figure, and a line under
    it saying what it counts. The caller lays the cards out in a grid.
--}}
@props([
    'label',
    'value',
    'note' => null,
])

<div {{ $attributes->class(['hris-card min-w-0 p-4']) }}>
    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $label }}</dt>
    <dd>
        <span class="num mt-1 block text-2xl font-bold">{{ $value }}</span>
        @if ($note !== null)
            <span class="mt-0.5 block text-xs text-muted-foreground">{{ $note }}</span>
        @endif
    </dd>
</div>
