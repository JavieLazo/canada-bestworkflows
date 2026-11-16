{{--
    Import CSV — the one dialog every importable table uses (App\Livewire\Kit\CsvImport).

    Renders nothing for an actor the table's import does not allow. The
    button opens a dialog that explains the columns, links the template,
    and checks the picked file straight away: every row runs through the
    owner's own save and is rolled back, so the summary below is what an
    import would do. "Import" stays disabled until every row passes.

    Accessible name: Flux forwards only class/style/autofocus to the
    <dialog>, so x-init points its aria-labelledby at the heading (as the
    projects delete confirm does).
--}}
<div>
    @if ($allowed && $layout !== null)
        @php($headingId = 'csv-import-heading-'.$this->getId())
        @php($inputId = 'csv-import-file-'.$this->getId())

        @if ($showTrigger)
            <flux:button variant="outline" icon="arrow-up-tray" wire:click="openDialog">
                Import CSV
            </flux:button>
        @endif

        <flux:modal
            wire:model.self="open"
            class="w-full max-w-2xl"
            x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', '{{ $headingId }}')"
        >
            <div class="space-y-5">
                <div>
                    <flux:heading level="2" size="lg" id="{{ $headingId }}">Import {{ $layout->title }}</flux:heading>
                    <flux:text class="mt-2">
                        Upload a CSV with the same columns as this table's export.
                    </flux:text>
                </div>

                <ul class="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                    @if ($layout->keyRule !== null)
                        <li>{{ $layout->keyRule }}</li>
                    @else
                        <li>Leave <span class="font-medium text-foreground">{{ $layout->key }}</span> blank to add a new row. A row whose {{ $layout->key }} matches an existing one updates it. Nothing is ever deleted.</li>
                    @endif
                    <li>Every row is checked first. If any row has a problem, nothing is saved.</li>
                    @foreach ($layout->notes as $note)
                        <li wire:key="note-{{ $loop->index }}">{{ $note }}</li>
                    @endforeach
                    <li>Up to <span class="num">{{ number_format($maxRows) }}</span> rows and {{ $maxSize }} per file.</li>
                </ul>

                <div class="rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm">
                    <p class="font-medium">Columns</p>
                    <p class="mt-1 break-words text-muted-foreground">{{ implode(' · ', $layout->columns) }}</p>
                    @if ($template !== '')
                        <flux:button class="mt-3" size="sm" variant="ghost" icon="arrow-down-tray" href="{{ $template }}">
                            Download Template
                        </flux:button>
                    @endif
                </div>

                <div class="space-y-2">
                    <input
                        type="file"
                        id="{{ $inputId }}"
                        class="hidden"
                        accept=".csv,.txt,text/csv,text/plain"
                        wire:model="file"
                    />
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button
                            type="button"
                            variant="outline"
                            icon="paper-clip"
                            x-data
                            x-on:click="document.getElementById('{{ $inputId }}').click()"
                        >
                            {{ $fileName === null ? 'Choose CSV File' : 'Choose Another File' }}
                        </flux:button>
                        <p class="min-w-0 flex-1 truncate text-sm text-muted-foreground">
                            {{ $fileName ?? 'No file chosen' }}
                        </p>
                    </div>
                    <p class="text-sm text-muted-foreground" wire:loading wire:target="file,preview">
                        Checking every row&hellip;
                    </p>
                    <p class="text-sm text-muted-foreground" wire:loading wire:target="import">
                        Importing&hellip;
                    </p>
                </div>

                @if ($report !== null)
                    {{-- Dimmed, not removed, while a new pick or the import runs:
                         wire:loading.remove restores the box as inline-block,
                         which shrink-wraps the summary. --}}
                    <div class="space-y-3" wire:loading.class="opacity-50" wire:target="file,preview,import">
                        @if ($report['errors'] === [])
                            <div class="rounded-lg border border-border bg-primary-soft px-4 py-3 text-sm">
                                <p class="font-medium">Ready to import <span class="num">{{ $report['rows'] }}</span> {{ $report['rows'] === 1 ? 'row' : 'rows' }}.</p>
                                <p class="mt-1 text-muted-foreground">
                                    <span class="num">{{ $report['created'] }}</span> new ·
                                    <span class="num">{{ $report['updated'] }}</span> updated ·
                                    <span class="num">{{ $report['unchanged'] }}</span> unchanged
                                </p>
                            </div>
                        @else
                            <div class="rounded-lg border border-border px-4 py-3 text-sm">
                                <p class="font-medium text-danger">
                                    @if ($report['failed'] > 0)
                                        <span class="num">{{ $report['failed'] }}</span> of <span class="num">{{ $report['rows'] }}</span> {{ $report['rows'] === 1 ? 'row has' : 'rows have' }} a problem. Nothing will be imported until every row passes.
                                    @else
                                        This file cannot be imported.
                                    @endif
                                </p>
                            </div>
                            <div class="hris-card overflow-hidden">
                                <div class="hris-table-wrap max-h-64 overflow-y-auto">
                                    <table class="hris-table">
                                        <thead>
                                            <tr>
                                                <th class="w-20">Row</th>
                                                <th>Problem</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($report['errors'] as $error)
                                                <tr wire:key="import-error-{{ $loop->index }}">
                                                    <td class="num">{{ $error['row'] === 0 ? 'File' : $error['row'] }}</td>
                                                    <td class="whitespace-normal">{{ $error['message'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            @if ($report['failed'] > count($report['errors']))
                                <p class="text-sm text-muted-foreground">
                                    Showing the first <span class="num">{{ count($report['errors']) }}</span> of <span class="num">{{ $report['failed'] }}</span>.
                                </p>
                            @endif
                        @endif

                        @foreach ($report['notices'] as $notice)
                            <p class="text-sm text-muted-foreground" wire:key="import-notice-{{ $loop->index }}">{{ $notice }}</p>
                        @endforeach
                    </div>
                @endif

                <div class="flex items-center justify-end gap-2">
                    <flux:button variant="outline" wire:click="$set('open', false)">Cancel</flux:button>
                    <flux:button
                        variant="primary"
                        icon="arrow-up-tray"
                        wire:click="import"
                        wire:loading.attr="disabled"
                        wire:target="file,preview,import"
                        :disabled="$report === null || $report['errors'] !== [] || ($report['created'] + $report['updated']) === 0"
                    >
                        Import
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
