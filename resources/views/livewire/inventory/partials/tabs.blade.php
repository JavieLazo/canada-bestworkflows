{{-- Every inventory surface shares the same navigation and route names. --}}
<nav class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-lg bg-muted p-1 text-sm text-muted-foreground" aria-label="Inventory sections">
    @foreach (['items' => 'Items', 'receipts' => 'Memorandum receipts', 'incidents' => 'Condition & loss', 'disposals' => 'Disposals', 'counts' => 'Physical counts', 'reports' => 'Reports', 'catalogs' => 'Categories & locations'] as $tab => $label)
        @continue(! in_array($tab, ['items', 'receipts'], true) && ! app(\App\Support\Access::class)->mayReadAllData(\App\Domain\Enums\ModuleCode::INVENTORY))
        <a href="{{ route('inventory.'.$tab) }}" wire:navigate @if ((($activeInventoryTab ?? null) === $tab || request()->routeIs('inventory.'.$tab, 'inventory.'.$tab.'.*'))) aria-current="page" @endif class="inline-flex items-center justify-center rounded-md px-3 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ (($activeInventoryTab ?? null) === $tab || request()->routeIs('inventory.'.$tab, 'inventory.'.$tab.'.*')) ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}">{{ $label }}</a>
    @endforeach
</nav>
