{{-- The screen pages the table it was handed: the whole document, or one column set of a report too
     wide to read at once. Printing and CSV keep the complete filtered output. --}}
<section class="hris-card overflow-hidden print:hidden" aria-label="Report results">
    <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-3">
        @if ($columnSets !== [])
            <div role="group" aria-label="Columns shown" class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-lg bg-muted p-1 text-sm text-muted-foreground">
                @foreach ($columnSets as $key => $label)
                    <button type="button" wire:click="$set('columnsView', '{{ $key }}')" aria-pressed="{{ $columnsView === $key ? 'true' : 'false' }}"
                        class="rounded-md px-3 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $columnsView === $key ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}">{{ $label }}</button>
                @endforeach
            </div>
        @elseif ($type === 'employee-list' && $view !== 'analysis')
            <div role="group" aria-label="Start with" class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-lg bg-muted p-1 text-sm text-muted-foreground">
                @foreach (['directory' => 'Directory', 'profile' => 'Profiles', 'missing' => 'Missing Information'] as $key => $label)
                    <button type="button" wire:click="applyPreset('{{ $key }}')" aria-pressed="{{ $preset === $key ? 'true' : 'false' }}"
                        class="rounded-md px-3 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $preset === $key ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}">{{ $label }}</button>
                @endforeach
            </div>
        @elseif ($view === 'analysis')
            <h2 class="text-base font-semibold">Group Summary</h2>
        @endif
        <p class="ml-auto text-sm text-muted-foreground"><span class="num">{{ number_format(count($table['rows'])) }}</span> {{ count($table['rows']) === 1 ? 'row' : 'rows' }}</p>
    </header>
    <div class="hris-table-wrap hris-table-scroll" tabindex="0" role="region" aria-label="Report results table">
        <table class="hris-table reports-data-table">
            <thead>
                <tr>
                    @foreach ($table['columns'] as $column)
                        @php
                            $columnKey = $table['keys'][$loop->index] ?? '';
                            $numeric = in_array($loop->index, $table['numeric'], true);
                        @endphp
                        @if ($columnKey !== '' && isset($sortOptions[$columnKey]))
                            <x-kit.sort-header :field="$columnKey" :label="$column" :align="$numeric ? 'right' : 'left'" :class="$numeric ? 'reports-number' : ''" scope="col" />
                        @else
                            <th scope="col" @class(['reports-number' => $numeric])>{{ $column }}</th>
                        @endif
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($previewRows as $row)
                    <tr wire:key="report-preview-{{ $columnsView }}-{{ $previewRows->currentPage() }}-{{ $loop->index }}">
                        @foreach ($row as $cell)
                            <td @class(['align-top', 'num whitespace-nowrap reports-number' => in_array($loop->index, $table['numeric'], true)])>{{ $cell === '' ? '—' : $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr wire:key="empty"><x-kit.empty-state :colspan="max(1, count($table['columns']))" title="No records match this report." hint="Try a broader filter or a different period." /></tr>
                @endforelse
            </tbody>
            @if ($table['totals'] !== [] && $table['rows'] !== [])
                <tfoot>
                    <tr>
                        @foreach ($table['totals'] as $cell)
                            <td @class(['font-semibold', 'num whitespace-nowrap reports-number' => in_array($loop->index, $table['numeric'], true)])>{{ $cell }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <x-kit.pagination :paginator="$previewRows" noun="rows" />
    @if ($document->notes !== [])
        <details class="border-t border-border px-5 py-3 text-xs text-muted-foreground">
            <summary class="cursor-pointer text-sm font-medium text-foreground focus-visible:outline-primary">About These Figures</summary>
            <ol class="mt-2 list-decimal space-y-1 pl-4 leading-relaxed">
                @foreach ($document->notes as $note)<li>{{ $note }}</li>@endforeach
            </ol>
            <p class="mt-2">Prepared {{ $generatedAt }} (Manila).</p>
        </details>
    @endif
</section>
