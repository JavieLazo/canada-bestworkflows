{{-- Expandable supporting tables keep a detailed analysis readable; print includes every section. --}}
<div class="space-y-4 print:hidden" aria-label="Supporting analysis">
    @foreach ($document->sections as $section)
        <details class="hris-card overflow-hidden" wire:ignore.self wire:key="analysis-section-{{ $type }}-{{ $analysisFilters['analysis'] ?? 'attendance' }}-{{ $loop->index }}" @if ($loop->index < 2) open @endif>
            <summary class="cursor-pointer px-5 py-4 text-sm font-semibold focus-visible:outline-primary">{{ $section['title'] }} <span class="ml-2 text-xs font-normal text-muted-foreground">{{ number_format(count($section['rows'])) }} rows</span></summary>
            <div class="border-t border-border">
                @if (($section['highlights'] ?? []) !== [])
                    <dl class="grid gap-4 border-b border-border p-5 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ($section['highlights'] as $metric)
                            <div><dt class="text-xs text-muted-foreground">{{ $metric['label'] }}</dt><dd class="num mt-1 text-lg font-semibold">{{ $metric['value'] }}</dd></div>
                        @endforeach
                    </dl>
                @endif
                <div class="hris-table-wrap hris-table-scroll" tabindex="0" role="region" aria-label="{{ $section['title'] }}">
                    <table class="hris-table reports-data-table">
                        <thead><tr>@foreach ($section['columns'] as $column)<th scope="col" @class(['reports-number' => in_array($loop->index, $section['numericColumns'] ?? [], true)])>{{ $column }}</th>@endforeach</tr></thead>
                        <tbody>
                            @forelse ($section['rows'] as $row)
                                <tr>@foreach ($row as $cell)<td @class(['align-top', 'num whitespace-nowrap reports-number' => in_array($loop->index, $section['numericColumns'] ?? [], true)])>{{ $cell === '' ? '—' : $cell }}</td>@endforeach</tr>
                            @empty
                                <tr><x-kit.empty-state :colspan="max(1, count($section['columns']))" title="No matching records in this section." /></tr>
                            @endforelse
                        </tbody>
                        @if (($section['totals'] ?? []) !== [] && $section['rows'] !== [])
                            <tfoot><tr>@foreach ($section['totals'] as $cell)<td @class(['font-semibold', 'num whitespace-nowrap reports-number' => in_array($loop->index, $section['numericColumns'] ?? [], true)])>{{ $cell }}</td>@endforeach</tr></tfoot>
                        @endif
                    </table>
                </div>
                @if (($section['notes'] ?? []) !== [])
                    <div class="space-y-1 border-t border-border px-5 py-3 text-xs leading-relaxed text-muted-foreground">@foreach ($section['notes'] as $note)<p>{{ $note }}</p>@endforeach</div>
                @endif
            </div>
        </details>
    @endforeach
</div>
