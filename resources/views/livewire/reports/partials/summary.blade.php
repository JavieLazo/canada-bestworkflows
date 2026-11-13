{{-- The answer first: the owner's few figures in one frame. A payroll report reads as the sum it is,
     gross less deductions is net, with the result marked; nothing here is calculated on the screen. --}}
@php
    $metrics = collect($document->summary)->keyBy('label');
    $pay = $metrics->has('Gross pay') && $metrics->has('Total deductions') && $metrics->has('Net pay');
@endphp
@if ($document->summary !== [])
    <dl class="hris-card flex flex-wrap items-stretch overflow-hidden print:hidden" aria-label="Report totals">
        @if ($pay)
            <div class="min-w-0 flex-1 basis-44 px-5 py-4">
                <dt class="text-sm text-muted-foreground">Gross pay</dt>
                <dd class="num mt-1 text-xl font-semibold whitespace-nowrap">{{ $metrics['Gross pay']['value'] }}</dd>
            </div>
            <span class="self-center px-1 text-xl text-muted-foreground" aria-hidden="true">−</span>
            <div class="min-w-0 flex-1 basis-44 px-5 py-4">
                <dt class="text-sm text-muted-foreground">Deductions</dt>
                <dd class="num mt-1 text-xl font-semibold whitespace-nowrap">{{ $metrics['Total deductions']['value'] }}</dd>
            </div>
            <span class="self-center px-1 text-xl text-muted-foreground" aria-hidden="true">=</span>
            <div class="min-w-0 flex-1 basis-44 bg-primary-soft px-5 py-4">
                <dt class="text-sm text-muted-foreground">Net pay</dt>
                <dd class="num mt-1 text-xl font-semibold whitespace-nowrap">{{ $metrics['Net pay']['value'] }}</dd>
            </div>
            @foreach ($metrics->except(['Gross pay', 'Total deductions', 'Net pay']) as $metric)
                <div class="min-w-0 basis-32 border-l border-border px-5 py-4">
                    <dt class="text-sm text-muted-foreground">{{ $metric['label'] }}</dt>
                    <dd class="num mt-1 text-xl font-semibold whitespace-nowrap">{{ $metric['value'] }}</dd>
                </div>
            @endforeach
        @else
            @foreach ($document->summary as $metric)
                <div @class(['min-w-0 flex-1 basis-40 px-5 py-4', 'sm:border-l sm:border-border' => ! $loop->first])>
                    <dt class="text-sm text-muted-foreground">{{ $metric['label'] }}</dt>
                    <dd class="num mt-1 text-xl font-semibold">{{ $metric['value'] }}</dd>
                </div>
            @endforeach
        @endif
    </dl>
@endif
