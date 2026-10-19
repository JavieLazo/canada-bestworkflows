{{-- Detached owner-produced facts; payslips use the shared compact print layout with supporting details on screen. --}}
@php
    use App\Support\HrisFormat;
@endphp
<div @class(['record-print reports-document', 'reports-document-statements' => $type === 'payslips'])>
    <header @class(['reports-document-header', 'print:hidden' => in_array($type, ['payslips', 'attendance-reports'], true) && $document->sections !== [] && ($generated['view'] ?? '') !== 'analysis'])>
        <div class="reports-document-heading">
            <div class="reports-brand">
                <x-brand-logo />
                <div class="min-w-0">
                    <p class="font-semibold">{{ config('copems.company_name') }}</p>
                    <p class="reports-overline">Management Information System</p>
                </div>
            </div>
            <span class="reports-document-label">Internal report</span>
        </div>
        <div class="reports-document-title">
            <div class="min-w-0">
                <h2>{{ $document->title }}</h2>
                <p>{{ $type === 'employee-list' ? 'Current employee records' : HrisFormat::date($generated['from']).' – '.HrisFormat::date($generated['to']) }}</p>
                @if ($type === 'employee-list' && ($generated['view'] ?? '') === 'analysis')
                    <p>Activity window: {{ HrisFormat::date($generated['from']) }} – {{ HrisFormat::date($generated['to']) }}</p>
                @endif
            </div>
            <dl class="reports-document-meta">
                <div><dt>Generated</dt><dd>{{ $generatedAt }}</dd></div>
                <div><dt>Time zone</dt><dd>Manila</dd></div>
                @if (in_array($type, ['payroll-register', 'payslips', 'government-contributions', 'payroll-summary', 'loan-summary'], true))
                    <div><dt>Currency</dt><dd>Philippine peso (PHP)</dd></div>
                @endif
            </dl>
        </div>
        @if ($document->summary !== [])
            <dl class="reports-summary" aria-label="Report totals">
                @foreach ($document->summary as $metric)
                    <div class="min-w-0">
                        <dt>{{ $metric['label'] }}</dt>
                        <dd class="num">{{ $metric['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </header>

    <div class="reports-document-body">
        @if ($document->charts !== [])
            @include('livewire.reports.partials.charts')
        @endif
        @if (($generated['view'] ?? '') === 'analysis')
            <section class="reports-detail-section">
                <header class="reports-section-heading"><h3>Group summary</h3></header>
                @include('livewire.reports.partials.table', ['columns' => $document->columns, 'reportRows' => $document->rows, 'totals' => $document->totals, 'numericColumns' => $document->numericColumns])
            </section>
        @endif
        @if ($document->sections !== [])
            @foreach ($document->sections as $section)
                @php
                    $sectionNumber = $loop->iteration;
                    $sectionKind = $section['kind'] ?? ($type === 'payslips' ? 'payslip' : 'table');
                @endphp
                @if ($sectionKind === 'payslip')
                    @include('livewire.reports.partials.statement', ['statement' => $section, 'statementNumber' => $sectionNumber, 'statementCount' => count($document->sections)])
                @else
                    <article @class(['reports-detail-section', 'reports-attendance-sheet' => $sectionKind === 'attendance'])>
                        @if ($sectionKind === 'attendance')
                            <div class="reports-sheet-brand">
                                <span>{{ config('copems.company_name') }}</span>
                                <span>Attendance report <span class="num">{{ $sectionNumber }} / {{ count($document->sections) }}</span></span>
                            </div>
                        @endif
                        <header class="reports-section-heading">
                            <div class="min-w-0">
                                <p class="reports-overline">{{ $sectionKind === 'attendance' ? 'Employee attendance' : 'Section '.$sectionNumber }}</p>
                                <h3>{{ $section['title'] }}</h3>
                                @if ($sectionKind === 'attendance')
                                    <p class="reports-section-period">{{ HrisFormat::date($generated['from']) }} – {{ HrisFormat::date($generated['to']) }} <span aria-hidden="true">·</span> Generated {{ $generatedAt }} (Manila)</p>
                                @endif
                            </div>
                            <span class="reports-row-count num">{{ count($section['rows']) }} {{ count($section['rows']) === 1 ? 'row' : 'rows' }}</span>
                        </header>
                        @if (($section['identity'] ?? []) !== [])
                            <dl class="reports-sheet-identity">
                                @foreach ($section['identity'] as $field)
                                    <div><dt>{{ $field['label'] }}</dt><dd>{{ $field['value'] }}</dd></div>
                                @endforeach
                            </dl>
                        @endif
                        @if (($section['highlights'] ?? []) !== [])
                            <dl class="reports-summary reports-section-summary" aria-label="{{ $section['title'] }} totals">
                                @foreach ($section['highlights'] as $metric)
                                    <div class="min-w-0"><dt>{{ $metric['label'] }}</dt><dd class="num">{{ $metric['value'] }}</dd></div>
                                @endforeach
                            </dl>
                        @endif
                        @include('livewire.reports.partials.table', ['columns' => $section['columns'], 'reportRows' => $section['rows'], 'totals' => $section['totals'] ?? [], 'numericColumns' => $section['numericColumns'] ?? [], 'tableTitle' => $section['title'], 'tableKey' => 'section-'.$sectionNumber])
                        @if (($section['notes'] ?? []) !== [])
                            <div class="reports-section-notes">
                                @foreach ($section['notes'] as $note)<p>{{ $note }}</p>@endforeach
                            </div>
                        @endif
                    </article>
                @endif
            @endforeach
        @else
            <section class="reports-detail-section">
                <header class="reports-section-heading">
                    <h3>Report detail</h3>
                    <span class="reports-row-count num">{{ count($document->rows) }} {{ count($document->rows) === 1 ? 'row' : 'rows' }}</span>
                </header>
                @include('livewire.reports.partials.table', ['columns' => $document->columns, 'reportRows' => $document->rows, 'totals' => $document->totals, 'numericColumns' => $document->numericColumns])
            </section>
        @endif
    </div>

    @if ($document->notes !== [])
        <footer @class(['reports-source-notes', 'reports-statement-appendix' => $type === 'payslips' && $document->sections !== []])>
            <h3>Source and coverage notes</h3>
            <ol>
                @foreach ($document->notes as $note)<li>{{ $note }}</li>@endforeach
            </ol>
            <p class="reports-document-end">End of report <span aria-hidden="true">·</span> Generated {{ $generatedAt }} (Manila)</p>
        </footer>
    @endif
</div>
