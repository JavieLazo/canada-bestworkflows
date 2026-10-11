{{-- The screen pages the detached report; printing and CSV retain the complete filtered output. --}}
@if ($document->summary !== [])
    <dl class="grid grid-cols-2 gap-3 print:hidden {{ count($document->summary) === 6 ? 'xl:grid-cols-3' : 'xl:grid-cols-4' }}" aria-label="Report totals">
        @foreach ($document->summary as $metric)
            <div class="hris-card min-w-0 px-4 py-3">
                <dt class="text-xs text-muted-foreground">{{ $metric['label'] }}</dt>
                <dd class="num mt-1 text-xl font-semibold">{{ $metric['value'] }}</dd>
            </div>
        @endforeach
    </dl>
@endif
@if ($document->charts !== [])
    <div class="print:hidden">@include('livewire.reports.partials.charts')</div>
@endif
<section class="hris-card overflow-hidden print:hidden" aria-label="Report results">
    <div class="hris-table-wrap hris-table-scroll" tabindex="0" role="region" aria-label="Report results table">
        <table class="hris-table reports-data-table">
            <thead>
                <tr>
                    @foreach ($document->columns as $column)
                        @php($columnKey = $document->columnKeys[$loop->index] ?? '')
                        @if ($columnKey !== '' && isset($sortOptions[$columnKey]))
                            <x-kit.sort-header :field="$columnKey" :label="$column" :align="in_array($loop->index, $document->numericColumns, true) ? 'right' : 'left'" :class="in_array($loop->index, $document->numericColumns, true) ? 'reports-number' : ''" scope="col" />
                        @else
                            <th scope="col" @class(['reports-number' => in_array($loop->index, $document->numericColumns, true)])>{{ $column }}</th>
                        @endif
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($previewRows as $row)
                    <tr wire:key="report-preview-{{ $previewRows->currentPage() }}-{{ $loop->index }}">
                        @foreach ($row as $cell)
                            <td @class(['align-top', 'num whitespace-nowrap reports-number' => in_array($loop->index, $document->numericColumns, true)])>{{ $cell === '' ? '—' : $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><x-kit.empty-state :colspan="max(1, count($document->columns))" title="No records match this report." hint="Try a broader filter or a different period, then generate again." /></tr>
                @endforelse
            </tbody>
            @if ($document->totals !== [] && $document->rows !== [])
                <tfoot>
                    <tr>
                        @foreach ($document->totals as $cell)
                            <td @class(['font-semibold', 'num whitespace-nowrap reports-number' => in_array($loop->index, $document->numericColumns, true)])>{{ $cell }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <x-kit.pagination :paginator="$previewRows" noun="rows" />
</section>
