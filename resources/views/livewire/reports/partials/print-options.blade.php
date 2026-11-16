{{-- Print options: the reader ticks which parts and which columns go on paper, then prints. Every
     choice lives in the browser and only hides things on the sheet — the document, its totals and
     the CSV are untouched, and a new report starts again with everything ticked. The choices are
     one generated stylesheet over the sheet's `data-print-part` / `data-print-table` names, so the
     sheets shown on screen follow them too. --}}
@if ($printOptions['parts'] !== [] || $printOptions['tables'] !== [])
    <div class="print:hidden" wire:key="report-print-options-{{ md5((string) json_encode($printOptions)) }}"
        x-data="{
            parts: @js(array_fill_keys(array_column($printOptions['parts'], 'key'), true)),
            columns: @js(collect($printOptions['tables'])->mapWithKeys(fn (array $table): array => [$table['key'] => array_fill(0, count($table['columns']), true)])->all()),
            get css() {
                const rules = [];
                for (const [key, on] of Object.entries(this.parts)) {
                    if (! on) rules.push(`.reports-document [data-print-part='${key}'] { display: none !important; }`);
                }
                for (const [table, shown] of Object.entries(this.columns)) {
                    shown.forEach((on, index) => {
                        if (! on) rules.push(`.reports-document [data-print-table='${table}'] tr > :nth-child(${index + 1}) { display: none !important; }`);
                    });
                }
                return rules.join(' ');
            },
            get left() {
                return Object.values(this.parts).filter((on) => ! on).length
                    + Object.values(this.columns).flat().filter((on) => ! on).length;
            },
            everything() {
                Object.keys(this.parts).forEach((key) => this.parts[key] = true);
                Object.keys(this.columns).forEach((table) => this.columns[table] = this.columns[table].map(() => true));
            },
        }">
        <style x-text="css"></style>
        <flux:modal name="report-print" class="w-full max-w-3xl print:hidden" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'report-print-heading')">
            <div class="space-y-5">
                <header>
                    <h2 id="report-print-heading" class="text-lg font-semibold">Print Options</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Untick what you do not want on paper. The report itself does not change.</p>
                </header>
                <div class="grid gap-6 md:grid-cols-2">
                    @if ($printOptions['parts'] !== [])
                        <fieldset class="space-y-3">
                            <legend class="text-sm font-semibold">Parts of the Sheet</legend>
                            @foreach ($printOptions['parts'] as $part)
                                <flux:checkbox x-model="parts['{{ $part['key'] }}']" label="{{ $part['label'] }}" />
                            @endforeach
                        </fieldset>
                    @endif
                    @foreach ($printOptions['tables'] as $table)
                        <fieldset class="space-y-3">
                            <legend class="text-sm font-semibold">{{ $table['label'] }}</legend>
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ($table['columns'] as $column)
                                    <flux:checkbox x-model="columns['{{ $table['key'] }}'][{{ $loop->index }}]" label="{{ $column }}" />
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div>
                <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                    <p class="text-xs text-muted-foreground" role="status">
                        <span x-show="left === 0">Everything will be printed.</span>
                        <span x-show="left > 0" x-cloak><span class="num" x-text="left"></span> left off the paper. <button type="button" class="font-medium text-primary hover:underline" x-on:click="everything()">Print Everything</button></span>
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                        <flux:button type="button" variant="primary" icon="printer" x-on:click="$flux.modal('report-print').close(); setTimeout(() => window.print(), 200)">Print</flux:button>
                    </div>
                </footer>
            </div>
        </flux:modal>
    </div>
@endif
