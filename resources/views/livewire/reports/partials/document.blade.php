{{-- A report on paper: A4 portrait sheets in the payslip's style, from the owner's detached facts.
     Every sheet reads the same way — PPMC's header, one ruled block with what the report covers and
     its answer, framed tables, then the sign-off and one closing line. Payslips keep their own sheet.
     Attendance analysis prints its graphs as tables; nothing on a sheet is calculated here.
     `data-print-part` and `data-print-table` name what the Print options dialog can leave off the paper. --}}
@php
    use App\Livewire\Payroll\Support\PayslipDocument;
    use App\Support\HrisFormat;
    use Illuminate\Support\Str;

    $printView = $generated['view'] ?? '';
    $printAnalysis = $printView === 'analysis';
    $periodLine = $type === 'employee-list' && ! $printAnalysis
        ? 'As of '.HrisFormat::date($generated['to'])
        : ($generated['from'] === $generated['to'] ? HrisFormat::date($generated['to']) : HrisFormat::date($generated['from']).' – '.HrisFormat::date($generated['to']));
    $payTotals = collect($document->summary)->pluck('label')->contains('Net pay');
@endphp
<div @class(['record-print reports-document', 'reports-document-statements' => $type === 'payslips'])>
    @if ($type === 'payslips' && $document->sections !== [])
        @php $payslipSheets = PayslipDocument::reportSheets($document->sections); @endphp
        @foreach ($payslipSheets as $sheet)
            @include('livewire.reports.partials.statement', ['sheet' => $sheet, 'statementNumber' => $loop->iteration, 'statementCount' => count($payslipSheets)])
        @endforeach
    @elseif ($sheets)
        @foreach ($document->sections as $section)
            <article class="reports-sheet hris-always-light" wire:key="report-sheet-{{ $loop->index }}">
                @include('livewire.reports.partials.sheet-header', ['sheetTitle' => $printTitle, 'sheetPeriod' => $periodLine])
                @if (($section['identity'] ?? []) !== [])
                    <dl class="reports-sheet-block" aria-label="Employee">
                        @foreach ($section['identity'] as $field)
                            <div><dt>{{ $field['label'] }}</dt><dd>{{ $field['value'] }}</dd></div>
                        @endforeach
                    </dl>
                @endif
                @if (($section['highlights'] ?? []) !== [])
                    <dl class="reports-sheet-block" aria-label="Totals for the period" data-print-part="totals">
                        @foreach ($section['highlights'] as $metric)
                            <div><dt>{{ $metric['label'] }}</dt><dd class="num">{{ $metric['value'] }}</dd></div>
                        @endforeach
                    </dl>
                @endif
                <section class="reports-sheet-frame">
                    <div class="reports-sheet-band"><h3>Attendance, Day by Day</h3><span>Sheet <span class="num">{{ $loop->iteration }}</span> of <span class="num">{{ count($document->sections) }}</span></span></div>
                    @include('livewire.reports.partials.sheet-table', ['columns' => $section['columns'], 'reportRows' => $section['rows'], 'totals' => $section['totals'] ?? [], 'numericColumns' => $section['numericColumns'] ?? [], 'tableTitle' => $section['title'], 'printTable' => 'main'])
                </section>
                @php $sheetNotes = array_values(array_filter($section['notes'] ?? [], static fn (string $note): bool => ! str_starts_with($note, 'Position: '))); @endphp
                @if ($sheetNotes !== [])
                    <div class="reports-sheet-notes" data-print-part="notes">@foreach ($sheetNotes as $note)<p>{{ $note }}</p>@endforeach</div>
                @endif
                <div class="reports-sheet-end">
                    <div class="reports-sheet-sign" aria-label="Signatures" data-print-part="sign">
                        <div><p>Certified true and correct:</p><div></div><p>{{ collect($section['identity'] ?? [])->firstWhere('label', 'Employee')['value'] ?? "\u{00A0}" }}</p><p>Employee</p></div>
                        <div><p>Verified by:</p><div></div><p>&nbsp;</p><p>Head of Office</p></div>
                    </div>
                    <p class="reports-sheet-foot"><span>Times are as recorded in the Daily Time Record.</span><span>Generated {{ $generatedAt }}</span></p>
                </div>
            </article>
        @endforeach
    @else
        <article class="reports-sheet hris-always-light">
            @include('livewire.reports.partials.sheet-header', ['sheetTitle' => $printTitle, 'sheetPeriod' => $periodLine])
            @if ($document->summary !== [])
                <dl class="reports-sheet-block" aria-label="What this report covers" data-print-part="totals">
                    @foreach ($document->summary as $metric)
                        <div @class(['reports-sheet-key' => $payTotals ? $metric['label'] === 'Net pay' : $loop->first])><dt>{{ $metric['label'] }}</dt><dd class="num">{{ $metric['value'] }}</dd></div>
                    @endforeach
                </dl>
            @endif

            @if ($printAnalysis && $type === 'attendance-reports')
                {{-- On paper a graph is its figures: each one prints as a table of its points. --}}
                @foreach ($document->charts as $chart)
                    <section class="reports-sheet-frame" data-print-part="chart-{{ $loop->index }}">
                        <div class="reports-sheet-band"><h3>{{ $chart['title'] }}</h3><span>{{ $chart['unit'] }}</span></div>
                        <table class="hris-table reports-sheet-table reports-sheet-pairs">
                            <caption class="sr-only">{{ $chart['title'] }}</caption>
                            <tbody>
                                @forelse (array_chunk($chart['points'], 4) as $points)
                                    <tr>
                                        @foreach ($points as $point)
                                            <th scope="row">{{ $point['label'] }}</th>
                                            <td class="num">{{ number_format($point['value'], fmod((float) $point['value'], 1.0) === 0.0 ? 0 : 1) }}</td>
                                        @endforeach
                                        @for ($blank = count($points); $blank < 4; $blank++)<th></th><td></td>@endfor
                                    </tr>
                                @empty
                                    <tr><td>No qualifying data.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </section>
                @endforeach
            @elseif ($printAnalysis && $document->charts !== [])
                <div class="reports-sheet-charts" data-print-part="charts">@include('livewire.reports.partials.charts')</div>
            @endif

            @if ($type === 'payroll-register' && $document->sections !== [])
                @foreach ($document->sections as $section)
                    @php
                        // One payroll on the sheet: its name is said once in the band, not on every row.
                        $payrolls = array_values(array_unique(array_column($section['rows'], 2)));
                        $onePayroll = count($payrolls) === 1 && ($section['columns'][2] ?? '') === 'Payroll / period';
                        $drop = static fn (array $cells): array => $onePayroll ? [...array_slice($cells, 0, 2), ...array_slice($cells, 3)] : $cells;
                        $numeric = array_map(static fn (int $index): int => $onePayroll && $index > 2 ? $index - 1 : $index, $section['numericColumns'] ?? []);
                    @endphp
                    <section class="reports-sheet-frame" data-print-part="section-{{ $loop->index }}">
                        <div class="reports-sheet-band"><h3>{{ $section['title'] }}</h3><span>{{ $onePayroll ? $payrolls[0] : 'Philippine pesos' }}</span></div>
                        @include('livewire.reports.partials.sheet-table', ['columns' => $drop($section['columns']), 'reportRows' => array_map($drop, $section['rows']), 'totals' => $drop($section['totals'] ?? []), 'numericColumns' => $numeric, 'tableTitle' => $section['title']])
                    </section>
                @endforeach
            @else
                <section class="reports-sheet-frame" data-print-part="main">
                    <div class="reports-sheet-band">
                        <h3>{{ $printAnalysis ? 'Group Summary' : Str::of($catalog[$type]['lead'])->before('.') }}</h3>
                        <span><span class="num">{{ number_format(count($document->rows)) }}</span> {{ count($document->rows) === 1 ? 'row' : 'rows' }}</span>
                    </div>
                    @include('livewire.reports.partials.sheet-table', ['columns' => $document->columns, 'reportRows' => $document->rows, 'totals' => $document->totals, 'numericColumns' => $document->numericColumns, 'tableTitle' => $document->title, 'printTable' => 'main'])
                </section>
                @if ($printAnalysis)
                    @foreach ($document->sections as $section)
                        <section class="reports-sheet-frame" data-print-part="section-{{ $loop->index }}">
                            <div class="reports-sheet-band"><h3>{{ $section['title'] }}</h3><span><span class="num">{{ number_format(count($section['rows'])) }}</span> {{ count($section['rows']) === 1 ? 'row' : 'rows' }}</span></div>
                            @include('livewire.reports.partials.sheet-table', ['columns' => $section['columns'], 'reportRows' => $section['rows'], 'totals' => $section['totals'] ?? [], 'numericColumns' => $section['numericColumns'] ?? [], 'tableTitle' => $section['title']])
                        </section>
                    @endforeach
                @endif
            @endif

            <div class="reports-sheet-end">
                {{-- The register is signed before it leaves Finance: the signatories as they stand when printed (Payroll Settings › Signatories). --}}
                @if ($type === 'payroll-register')
                    <div data-print-part="sign">@include('livewire.payroll.partials.signatories', ['signatories' => app(\App\Modules\Payroll\Application\Queries\SignatoryRecords::class)->all(app(\App\Support\Access::class))])</div>
                @endif
                <p class="reports-sheet-foot">
                    <span>{{ in_array($type, \App\Livewire\Reports\Support\ReportCatalog::PAYROLL, true) ? 'Confidential payroll information, from posted payslips.' : 'From '.$catalog[$type]['source'].'.' }}</span>
                    <span>Generated {{ $generatedAt }}</span>
                </p>
            </div>
        </article>
    @endif
</div>
@if ($sheets && $document->notes !== [])
    <details class="hris-card mt-4 px-5 py-3 text-xs text-muted-foreground print:hidden">
        <summary class="cursor-pointer text-sm font-medium text-foreground focus-visible:outline-primary">About These Figures</summary>
        <ol class="mt-2 list-decimal space-y-1 pl-4 leading-relaxed">
            @foreach ($document->notes as $note)<li>{{ $note }}</li>@endforeach
        </ol>
    </details>
@endif
