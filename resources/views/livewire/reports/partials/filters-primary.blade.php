{{-- The filters a reader reaches for first sit in the toolbar row, each showing its own value. --}}
@if ($type === 'attendance-reports' && $view === 'summary')
    <div class="w-full sm:w-44">
        <x-kit.select wire:model.live="filters.issue" aria-label="Attendance condition">
            <flux:select.option value="all">Everyone</flux:select.option>
            <flux:select.option value="late">Late</flux:select.option>
            <flux:select.option value="undertime">Undertime</flux:select.option>
            <flux:select.option value="absent">Absent</flux:select.option>
            <flux:select.option value="incomplete">Incomplete days</flux:select.option>
        </x-kit.select>
    </div>
    <div class="w-full sm:w-56">
        <flux:input type="search" icon="magnifying-glass" wire:model.live.debounce.350ms="filters.search" placeholder="Search name or ID" aria-label="Search selected employees" />
    </div>
@elseif ($type === 'attendance-reports' && $view === 'analysis')
    <div class="w-full sm:w-48">
        <x-kit.select wire:model.live="analysisFilters.groupBy" aria-label="Analysis grouping">
            @foreach ($analysisGroups as $key => $label)<flux:select.option :value="$key">By {{ strtolower($label) }}</flux:select.option>@endforeach
        </x-kit.select>
    </div>
    <div class="w-full sm:w-56">
        <x-kit.select wire:model.live="analysisFilters.compare" aria-label="Attendance comparison period">
            <flux:select.option value="none">No comparison</flux:select.option>
            <flux:select.option value="previous">Compare with the period before</flux:select.option>
        </x-kit.select>
    </div>
@elseif ($type === 'employee-list')
    <div class="w-full sm:w-56">
        <flux:input type="search" icon="magnifying-glass" wire:model.live.debounce.350ms="filters.search" placeholder="Search name, ID or position" aria-label="Search employees" />
    </div>
    @foreach (['department' => 'Department or Unit', 'active' => 'Active Status'] as $key => $label)
        <div class="w-full sm:w-48">
            <x-kit.select wire:model.live="filters.{{ $key }}" :aria-label="$label">
                @foreach ($employeeOptions[$key] ?? [] as $value => $option)
                    <flux:select.option :value="$value">{{ $option }}</flux:select.option>
                @endforeach
            </x-kit.select>
        </div>
    @endforeach
    @if ($view === 'analysis')
        <div class="w-full sm:w-52">
            <x-kit.select wire:model.live="analysisFilters.analysis" aria-label="Analysis topic">
                @foreach ($analysisTopics as $key => $label)<flux:select.option :value="$key">{{ $label }}</flux:select.option>@endforeach
            </x-kit.select>
        </div>
    @endif
@endif
