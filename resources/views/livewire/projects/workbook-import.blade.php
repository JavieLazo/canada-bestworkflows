@use('App\Modules\WorkManagement\Application\ReportDefinitions')
<div>
    @if ($showTrigger && $modes !== [])<flux:button size="sm" variant="ghost" icon="arrow-up-tray" wire:click="$set('open', true)">Import XLSX</flux:button>
    @endif
    <flux:modal wire:model.self="open" class="w-full max-w-4xl"><div class="space-y-5"><flux:heading size="lg" level="2">Import workbook rows</flux:heading>
        <p class="text-sm text-muted-foreground">Preview additions and updates before importing. Matching IDs can update existing records.</p>
        <div class="grid gap-4 sm:grid-cols-2"><flux:input type="file" wire:model="upload" label="XLSX workbook · up to 10 MB" accept=".xlsx" /><x-kit.select wire:model.live="mode" label="Destination" aria-label="Workbook import destination">@foreach ($modes as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
            @endforeach
        </x-kit.select></div><flux:error name="upload" />
        @if ($sheets !== [])
            <div class="grid items-end gap-4 sm:grid-cols-3"><x-kit.select wire:model.live="sheetName" label="Worksheet" aria-label="Import worksheet">@foreach ($sheets as $sheet)<flux:select.option value="{{ $sheet }}">{{ $sheet }}</flux:select.option>
                @endforeach
            </x-kit.select><flux:input type="number" min="1" max="100" wire:model.live.debounce.500ms="headerRow" label="Header row" /><flux:button variant="outline" wire:click="mapColumns">Match columns</flux:button></div>
            @if ($mode === 'BUDGET_LINE')<x-kit.select wire:model="options.budgetId" label="Budget draft" aria-label="Workbook target budget"><flux:select.option value="">Choose a draft</flux:select.option>@foreach ($budgets as $b)<flux:select.option value="{{ $b->id }}">Version {{ $b->version }}</flux:select.option>
                @endforeach
            </x-kit.select>
            @endif
            @if (in_array($mode, ['BUDGET_LINE', 'CASH_ENTRY'], true))<x-kit.select wire:model="options.categoryId" label="Default category · when the row has none" aria-label="Workbook default category"><flux:select.option value="">Choose a category</flux:select.option>@foreach ($categories as $c)<flux:select.option value="{{ $c->id }}">{{ $c->name }}</flux:select.option>
                @endforeach
            </x-kit.select>
            @endif
            @if ($mode === 'RESULT_ENTRY')<x-kit.select wire:model="options.indicatorId" label="Default indicator · when the row has none" aria-label="Workbook default indicator"><flux:select.option value="">Match from each row</flux:select.option>@foreach ($indicators as $i)<flux:select.option value="{{ $i->id }}">{{ $i->name }}</flux:select.option>
                @endforeach
            </x-kit.select>
            @endif
            @if ($mode === 'FIELDS')<x-kit.select wire:model.live="options.entity" label="Record kind" aria-label="Additional field record kind">@foreach ($fieldEntities as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                @endforeach
            </x-kit.select>
            @endif
            @if ($headers !== [])
                <details class="rounded-lg border border-border p-4"><summary class="w-fit text-sm font-medium">Source preview · first {{ count($sample) }} rows</summary><div class="mt-3 max-h-56 overflow-auto"><table class="hris-table w-full"><thead><tr>@foreach ($headers as $header)<th class="max-w-48 truncate">{{ $header }}</th>
                    @endforeach
                </tr></thead><tbody>@foreach ($sample as $row)<tr>@foreach ($headers as $index => $header)<td class="max-w-48 truncate" title="{{ $row['cells'][$index] ?? '' }}">{{ $row['cells'][$index] ?? '' }}</td>
                    @endforeach
                </tr>
                    @endforeach
                </tbody></table></div></details>
            @endif
            @if ($mapping !== [])
                <div class="grid max-h-96 gap-3 overflow-auto rounded-lg border border-border p-4 sm:grid-cols-2">@foreach ($columns as $key => $label)<x-kit.select wire:model="mapping.{{ $key }}" :label="$label" :aria-label="'Source column for '.$label" searchable><flux:select.option value="">Not imported</flux:select.option>@foreach ($headers as $index => $header)<flux:select.option value="{{ $index }}">{{ $index + 1 }} · {{ $header }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                    @endforeach
                </div>
                @if ($formulas > 0)<flux:checkbox wire:model="cachedValuesReviewed" :label="'I reviewed the saved results of '.$formulas.' formula cells. BestWorkflows will not execute these formulas.'" />
                @endif
                @if (in_array($mode, ['RESULT_ENTRY', 'CASH_ENTRY', 'CONTRIBUTION_DELIVERY'], true))<flux:textarea wire:model="options.reason" label="Reason · required when correcting an existing entry" rows="2" />
                @endif
                @if ($mode === 'TASK')<p class="text-xs text-muted-foreground">Blank task codes add work. Existing tasks keep their assignments, dates and status; edit those in the task panel or CSV import.</p>
                @endif
            @endif
        @endif
        @if ($result !== null)<div class="rounded-lg border border-border p-4 text-sm" role="status"><p class="num font-medium">{{ $result['saved'] ? 'Imported' : 'Preview' }} · {{ $result['created'] }} new · {{ $result['updated'] }} updated · {{ $result['unchanged'] }} unchanged · {{ $result['failed'] }} invalid</p>@foreach ($result['notices'] as $notice)<p class="mt-2 text-xs text-muted-foreground">{{ $notice }}</p>
            @endforeach
            @if ($result['errors'] !== [])<ul class="mt-3 max-h-48 space-y-1 overflow-auto text-xs text-destructive">@foreach ($result['errors'] as $error)<li>Row {{ $error['row'] }} · {{ $error['message'] }}</li>
                @endforeach
            </ul>
            @endif
        </div>
        @endif
        <flux:error name="workbook" /><flux:error name="headerRow" />
        <div class="flex flex-wrap justify-end gap-2"><flux:button variant="ghost" wire:click="close">Close</flux:button>@if ($mapping !== [] && $upload !== null)<flux:button variant="outline" wire:click="preview" wire:loading.attr="disabled">Preview changes</flux:button>@if ($previewStamp !== '' && $result !== null && $result['errors'] === [] && $result['rows'] > 0)<flux:button variant="primary" wire:click="import" wire:loading.attr="disabled">Import {{ $result['rows'] }} rows</flux:button>
            @endif
            @endif
        </div>
    </div></flux:modal>
</div>
