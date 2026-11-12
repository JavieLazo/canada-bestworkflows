{{-- Totals and numeric columns come from the owner; the complete table also prints beyond its screen scrollport.
     A cell holding a date and a time ("2026-09-01 12:00:00.456") is drawn as the day with the time under it;
     the cell's text, and so the CSV, stays as the owner wrote it. --}}
<div class="hris-table-wrap hris-table-scroll reports-table-wrap" tabindex="0" role="region" aria-label="{{ $tableTitle ?? $document->title }} table">
    <table class="hris-table reports-table">
        <caption class="sr-only">{{ $tableTitle ?? $document->title }}</caption>
        @if ($reportRows !== [])
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th scope="col" @class(['reports-number' => in_array($loop->index, $numericColumns ?? [], true)])>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        @endif
        <tbody>
            @forelse ($reportRows as $row)
                <tr wire:key="report-row-{{ $tableKey ?? 'main' }}-{{ $loop->index }}">
                    @foreach ($row as $cell)
                        <td @class(['align-top', 'reports-number num' => in_array($loop->index, $numericColumns ?? [], true), 'num' => preg_match('/^-?[\d.,:\/ -]+$/', $cell) === 1])>
                            @if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?: ?[AP]M)?)$/', $cell, $stamp) === 1)
                                <p class="whitespace-nowrap">{{ $stamp[1] }}</p>
                                <p class="whitespace-nowrap text-xs text-muted-foreground">{{ $stamp[2] }}</p>
                            @else
                                {{ $cell }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><x-kit.empty-state :colspan="count($columns)" title="No records match this report." hint="Adjust the period or employee selection and generate again." /></tr>
            @endforelse
        </tbody>
        @if (($totals ?? []) !== [] && $reportRows !== [])
            <tfoot>
                <tr>
                    @foreach ($totals as $total)
                        <td @class(['reports-number num' => in_array($loop->index, $numericColumns ?? [], true)])>{{ $total }}</td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
</div>
