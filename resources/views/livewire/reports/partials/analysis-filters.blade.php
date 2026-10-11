{{-- Owners define supported dimensions; analysis always includes the full selected cohort. --}}
<div class="grid gap-4 border-t border-border pt-4 sm:grid-cols-2 xl:grid-cols-3">
    @if ($type === 'employee-list')
        <x-kit.select label="Analysis topic" wire:model.live="analysisFilters.analysis" aria-label="Analysis topic">
            @foreach ($analysisTopics as $key => $label)<flux:select.option :value="$key">{{ $label }}</flux:select.option>@endforeach
        </x-kit.select>
    @endif
    <x-kit.select label="Group employees by" wire:model.live="analysisFilters.groupBy" aria-label="Analysis grouping">
        @foreach ($analysisGroups as $key => $label)<flux:select.option :value="$key">{{ $label }}</flux:select.option>@endforeach
    </x-kit.select>
    @if ($type === 'employee-list')
        <x-kit.select label="Cross-tabulate with" wire:model.live="analysisFilters.crossTab" aria-label="Analysis cross-tabulation">
            @foreach ($analysisCrossTabs as $key => $label)<flux:select.option :value="$key">{{ $label }}</flux:select.option>@endforeach
        </x-kit.select>
        <p class="text-xs leading-relaxed text-muted-foreground sm:col-span-2 xl:col-span-3">Counts and percentages use the same filtered employees throughout. Missing values appear separately. Training and recorded movements use the activity window; age, service and organizational placement use current records.</p>
    @else
        <x-kit.select label="Compare with" wire:model.live="analysisFilters.compare" aria-label="Attendance comparison period">
            <flux:select.option value="none">No previous period</flux:select.option>
            <flux:select.option value="previous">Previous period of equal length</flux:select.option>
        </x-kit.select>
        <flux:input label="Repeated lateness threshold (days)" type="number" min="1" max="31" step="1" wire:model.live.debounce.350ms="analysisFilters.threshold" />
        <p class="text-xs leading-relaxed text-muted-foreground sm:col-span-2 xl:col-span-3">Rates show their qualifying days and exclude uncertain schedules, open shifts, approved leave and official business where appropriate. The comparison uses the same employees and shares the 5,000 employee-day limit across both periods. The threshold identifies records for review; it is not a disciplinary finding.</p>
    @endif
</div>
