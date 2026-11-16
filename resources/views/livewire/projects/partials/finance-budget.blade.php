@if ($budgetCategory !== '')<div class="flex items-center gap-3 rounded-lg bg-muted px-4 py-3 text-xs"><span>{{ $categories->firstWhere('id', $budgetCategory)?->name }}</span><flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('budgetCategory', '')">All categories</flux:button></div>
@endif
@php
    use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
@endphp
@if ($budget === null)
    <div class="ppm-surface py-12 text-center"><h3 class="font-semibold">No budget plan yet</h3>
    </div>
@else
    <div class="flex flex-wrap items-center gap-3">
        <flux:dropdown position="bottom" align="start">
            <flux:button size="sm" variant="ghost" icon="clock" icon:trailing="chevron-down" aria-label="Budget history">{{ $budget->status === 'DRAFT' ? 'Draft' : 'Approved' }} · v{{ $budget->version }}</flux:button>
            <flux:menu>
                @foreach ($versions as $version)<flux:menu.item wire:click="$set('budgetChoice', '{{ $version->id }}')" :icon="$version->id === $budget->id ? 'check' : null">{{ $version->status === 'DRAFT' ? 'Draft' : 'Approved' }} · v{{ $version->version }}</flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
        @if ($budget->reason !== null)<flux:tooltip :content="$budget->reason"><flux:button size="sm" variant="ghost" square icon="information-circle" aria-label="Revision note" /></flux:tooltip>
        @endif
        <span class="num text-sm font-semibold">{{ $settings->currency }} {{ FinanceAmount::format($budgetTotal ?? 0) }}</span>
        <div class="flex items-center gap-2 sm:ml-auto">
            @if ($undoLineId !== null)<flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="undoRemove">Undo removal</flux:button>
            @endif
            @if ($permissions['admin'] && $budget->status === 'DRAFT')<flux:button size="sm" variant="outline" icon="check" wire:click="openApproval('{{ $budget->id }}', {{ $budget->revision }})">Approve budget</flux:button>
            @elseif ($permissions['edit'] && ! $versions->contains('status', 'DRAFT'))<flux:button size="sm" variant="outline" icon="pencil-square" wire:click="$set('revisionOpen', true)">Start revision</flux:button>
            @endif
        </div>
    </div>
    <div class="ppm-collection-toolbar"><flux:input size="sm" wire:model.live.debounce.250ms="search" icon="magnifying-glass" placeholder="Find a cost item…" aria-label="Search budget cost items" class="ppm-search" /><x-work.filters id="budget-filters" label="Budget filters" :count="$budgetCategory === '' ? 0 : 1"><x-kit.select wire:model.live="budgetCategory" label="Category" aria-label="Budget category"><flux:select.option value="">All categories</flux:select.option>@foreach ($categories as $category)<flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
    @endforeach</x-kit.select></x-work.filters></div>
    <div class="ppm-surface overflow-x-auto"><table class="hris-table ppm-table w-full">
        <thead><tr><x-kit.sort-header field="name" label="Cost item" /><th>Category</th><x-kit.sort-header field="basis" label="Cost basis" /><x-kit.sort-header field="amount" align="right">Amount ({{ $settings->currency }})</x-kit.sort-header>@if ($permissions['edit'] && $budget->status === 'DRAFT')<th><span class="sr-only">Actions</span></th>
        @endif</tr></thead>
        <tbody>
            @forelse ($lines as $line)
                <tr wire:key="cost-line-{{ $line->id }}"><td><span class="block max-w-72 truncate font-medium" title="{{ $line->label }}">{{ $line->label }}</span>
                    @if ($line->group_label !== null)<span class="mt-1 block max-w-72 truncate text-xs text-muted-foreground" title="{{ $line->group_label }}">{{ $line->group_label }}</span>
                    @endif
                    @if ($line->notes !== null)<details class="mt-1 max-w-80 text-xs"><summary class="text-muted-foreground">Notes</summary><p class="mt-2 whitespace-pre-wrap break-words">{{ $line->notes }}</p></details>
                    @endif
                </td><td>{{ $budget->status === 'APPROVED' ? $line->category_label : ($categories->firstWhere('id', $line->category_id)->name ?? $line->category_label) }}</td><td class="num text-xs text-muted-foreground">{{ $line->basis === 'FLAT' ? 'Flat amount' : FinanceAmount::quantityInput($line->quantity_scaled).' × '.$line->frequency.' × '.FinanceAmount::format($line->unit_minor) }}</td><td class="num text-right">{{ FinanceAmount::format($line->total_minor) }}</td>
                    @if ($permissions['edit'] && $budget->status === 'DRAFT')<td><flux:dropdown position="bottom" align="end"><flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Actions for cost item {{ $line->label }}" /><flux:menu><flux:menu.item wire:click="openLine('{{ $budget->id }}', '{{ $line->id }}')" icon="pencil-square">Edit</flux:menu.item><flux:menu.item variant="danger" wire:click="removeLine('{{ $budget->id }}', {{ $budget->revision }}, '{{ $line->id }}')" icon="trash">Remove</flux:menu.item></flux:menu></flux:dropdown></td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="5" class="py-10 text-center text-muted-foreground">{{ $search === '' ? 'Add cost items to build this budget.' : 'No cost items match your search.' }}</td></tr>
            @endforelse
        </tbody>
    </table></div>
    <x-kit.pagination :paginator="$lines" noun="cost items" class="!border-0 !px-0" />
@endif
