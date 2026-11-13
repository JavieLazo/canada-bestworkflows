{{-- One table of a printed sheet. Totals and numeric columns come from the owner; a cell holding a
     date and a time ("2026-09-01 12:00:00.456") is drawn as the day with the time under it, and the
     cell's text, and so the CSV, stays as the owner wrote it. --}}
<table @class(['hris-table reports-sheet-table', 'reports-sheet-dense' => count($columns) > 9]) @if (($printTable ?? '') !== '') data-print-table="{{ $printTable }}" @endif>
    <caption class="sr-only">{{ $tableTitle }}</caption>
    @if ($reportRows !== [])
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th scope="col" @class(['reports-number' => in_array($loop->index, $numericColumns, true)])>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
    @endif
    <tbody>
        @forelse ($reportRows as $row)
            <tr>
                @foreach ($row as $cell)
                    {{-- A short cell (a date, a time, a code) stays on one line; only real text wraps. --}}
                    <td @class(['reports-number num' => in_array($loop->index, $numericColumns, true), 'num' => preg_match('/^-?[\d.,:\/ -]+$/', $cell) === 1, 'reports-sheet-short' => mb_strlen($cell) <= 12])>
                        @if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?: ?[AP]M)?)$/', $cell, $stamp) === 1)
                            <p class="whitespace-nowrap">{{ $stamp[1] }}</p>
                            <p class="whitespace-nowrap">{{ $stamp[2] }}</p>
                        @else
                            {{ $cell === '' ? '–' : $cell }}
                        @endif
                    </td>
                @endforeach
            </tr>
        @empty
            <tr><td>No records match this report.</td></tr>
        @endforelse
    </tbody>
    @if ($totals !== [] && $reportRows !== [])
        <tfoot>
            <tr>
                @foreach ($totals as $total)
                    <td @class(['reports-number num' => in_array($loop->index, $numericColumns, true)])>{{ $total }}</td>
                @endforeach
            </tr>
        </tfoot>
    @endif
</table>
