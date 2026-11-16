@php
    use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
@endphp
<div class="space-y-5" aria-label="Project finance">
    @if (! $permissions['view'])
        <div class="ppm-surface p-10 text-center"><h2 class="font-semibold">Finance is no longer available to this account.</h2></div>
    @elseif ($settings === null)
        <section class="ppm-surface mx-auto max-w-xl space-y-5 p-6">
            <div class="space-y-2"><h2 class="text-lg font-semibold">Project finance</h2><p class="text-sm text-muted-foreground">Plan a budget and record cash received and paid expenses.</p></div>
            @if ($permissions['admin'])
                <form wire:submit="setupFinance" class="space-y-4">
                    <x-kit.select label="Currency" aria-label="Project finance currency" wire:model="currency">
                        @foreach (FinanceAmount::CURRENCIES as $code => $name)<flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                    <p class="text-xs text-muted-foreground">You can change the currency until the first cost or cash entry is recorded.</p>
                    <flux:button type="submit" variant="primary" icon="plus">Enable finance</flux:button>
                    <flux:error name="finance" />
                </form>
            @else
                <p class="text-sm text-muted-foreground">A project administrator can enable finance.</p>
            @endif
        </section>
    @else

        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-work.tabs aria-label="Financial records">
                @foreach (['overview' => 'Overview', 'budget' => 'Budget', 'cash' => 'Cash ledger', 'funding' => 'Funding & contributions'] as $value => $name)<x-work.tab :active="$section === $value" wire:click="$set('section', '{{ $value }}')">{{ $name }}</x-work.tab>
                @endforeach
            </x-work.tabs>
            <div class="flex items-center gap-2">
                <flux:dropdown position="bottom" align="end"><flux:button size="sm" square variant="ghost" icon="ellipsis-horizontal" aria-label="Finance tools" /><flux:menu>
                    @if ($budget !== null)<flux:menu.item icon="arrow-down-tray" href="{{ route('projects.finance.export', ['project' => $projectId, 'kind' => 'budget', 'budget' => $budget->id, 'q' => $section === 'budget' ? $search : '', 'category' => $budgetCategory]) }}">Export budget CSV</flux:menu.item>
                    @endif
                    @if ($permissions['edit'] && $budget?->status === 'DRAFT')<flux:menu.item icon="clipboard-document" wire:click="openPaste('{{ $budget->id }}')">Paste cost items</flux:menu.item>
                    @endif
                    <flux:menu.item icon="arrow-down-tray" href="{{ route('projects.finance.export', ['project' => $projectId, 'kind' => 'cash', 'q' => $section === 'cash' ? $search : '', 'type' => $kind, 'voided' => $showVoided ? 1 : 0, 'from' => $cashFrom, 'to' => $cashTo, 'category' => $cashCategory]) }}">Export cash CSV</flux:menu.item>
                    @if ($permissions['admin'] && $settings->can_change_currency)<flux:menu.item icon="cog-6-tooth" wire:click="openCurrency">Currency</flux:menu.item>
                    @endif
                    @if ($permissions['admin'])<flux:menu.item icon="users" wire:click="openAccess">Financial access</flux:menu.item>
                    @endif
                    @if ($permissions['edit'])<flux:menu.item icon="plus" wire:click="openCategory">Add category</flux:menu.item>
                    @endif
                </flux:menu></flux:dropdown>
                @if ($permissions['edit'] && ! in_array($section, ['budget', 'funding'], true))
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="openEntry(null, 'EXPENSE')">Paid expense</flux:button>
                    <flux:dropdown position="bottom" align="end"><flux:button size="sm" variant="outline" icon="chevron-down" aria-label="More cash actions" /><flux:menu><flux:menu.item wire:click="openEntry(null, 'RECEIPT')">Cash received</flux:menu.item></flux:menu></flux:dropdown>
                @elseif ($permissions['edit'] && $section === 'budget' && $budget === null)
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="startBudget">Create budget draft</flux:button>
                @elseif ($permissions['edit'] && $section === 'budget' && $budget?->status === 'DRAFT')
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="openLine('{{ $budget->id }}')">Add cost item</flux:button>
                @endif
            </div>
        </div>
        <flux:error name="budget" />
        <flux:error name="entry" />
        <flux:error name="finance" />

        @if ($section === 'overview')
            <div class="ppm-dashboard">
            <section class="ppm-surface ppm-chart-card" aria-label="Project financial summary">
                <header><h2 class="ppm-section-title">Budget and recorded cash</h2><div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-muted-foreground">Cash basis · through {{ \Carbon\CarbonImmutable::parse($asOf)->format('M j, Y') }}</span>
                </div></header>
                @if ($totals !== null)<x-work.finance-totals :totals="$totals" budget-action="showBudget" receipt-action="showCashKind('RECEIPT')" expense-action="showCashKind('EXPENSE')" />
                @endif
            </section>
            @include('livewire.projects.partials.finance-charts')
            </div>
        @elseif ($section === 'budget')
            @include('livewire.projects.partials.finance-budget')
        @elseif ($section === 'funding')
            <livewire:projects.project-funding-workspace :project-id="$projectId" :key="'funding-'.$projectId" />
        @else
            @include('livewire.projects.partials.finance-ledger')
        @endif
        @include('livewire.projects.partials.finance-dialogs')
    @endif
</div>
