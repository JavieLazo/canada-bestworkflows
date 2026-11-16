@use('App\Modules\WorkManagement\Domain\Projects\FinanceAmount')
<section class="space-y-3" aria-label="Cash allocation">
    <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="text-sm font-medium">Allocation</h3>@if ($editable && ! $editing)<flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit">{{ $entry->allocation_revision === 0 ? 'Classify / split' : 'Revise allocation' }}</flux:button>
        @endif
    </div>
    @if ($editing)
        <form wire:submit="save" class="space-y-4">
            @foreach ($splits as $index => $split)<fieldset class="space-y-3 rounded-lg border border-border p-3" wire:key="allocation-split-{{ $index }}"><legend class="px-1 text-xs">Split {{ $index + 1 }}</legend>
                <div class="flex items-start gap-2"><flux:input wire:model="splits.{{ $index }}.amount" label="Amount" inputmode="decimal" required /><flux:button type="button" size="sm" variant="ghost" class="text-danger!" square icon="x-mark" wire:click="remove({{ $index }})" aria-label="Remove split {{ $index + 1 }}" /></div>
                <x-kit.select wire:model.live="splits.{{ $index }}.source" label="Funding / contribution" aria-label="Split source" searchable><flux:select.option value="">No source</flux:select.option>@foreach ($sources as $source)<flux:select.option value="{{ $source['value'] }}">{{ $source['label'] }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                @if (str_starts_with($split['source'], 'FUNDING:'))<x-kit.select wire:model="splits.{{ $index }}.installmentId" label="Installment" aria-label="Split installment"><flux:select.option value="">No installment</flux:select.option>@foreach ($installments->where('funding_id', substr($split['source'], 8)) as $i)<flux:select.option value="{{ $i->id }}">{{ $i->name }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                @endif
                <x-kit.select wire:model="splits.{{ $index }}.groupId" label="Activity" aria-label="Split activity"><flux:select.option value="">No activity</flux:select.option>@foreach ($groups as $g)<flux:select.option value="{{ $g->id }}">{{ $g->name }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            </fieldset>
            @endforeach
            <flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="add">Add split</flux:button>
            @if ($revision > 0)<flux:textarea wire:model="reason" label="Reason for reclassification" rows="2" required />
            @endif
            <flux:error name="allocations" /><flux:error name="splits" /><div class="flex justify-end gap-2"><flux:button type="button" size="sm" variant="ghost" wire:click="$set('editing', false)">Cancel</flux:button><flux:button type="submit" size="sm" variant="primary">Save allocation</flux:button></div>
        </form>
    @else
        @if ($entry->allocation_revision > 1)<flux:input type="number" min="1" :max="$entry->allocation_revision" wire:model.live.debounce.500ms="historyRevision" label="Allocation revision" :placeholder="(string) $entry->allocation_revision" class="max-w-48" />
        @endif
        <ul class="space-y-2">@forelse ($rows as $row)<li class="flex items-start justify-between gap-3 text-sm"><div class="min-w-0"><span class="block truncate">{{ $row->source_label ?? 'No source' }}</span><span class="block text-xs text-muted-foreground">{{ implode(' · ', array_filter([$row->installment_label, $row->group_label])) }}</span></div><span class="num shrink-0">{{ FinanceAmount::format($row->amount_minor) }}</span></li>@empty<li class="text-sm text-muted-foreground">Unclassified. The entry already counts in cash totals.</li>
            @endforelse
        </ul>
        @if ($rows->first()?->reason)<p class="text-xs text-muted-foreground">{{ $rows->first()->reason }}</p>
        @endif
    @endif
</section>
