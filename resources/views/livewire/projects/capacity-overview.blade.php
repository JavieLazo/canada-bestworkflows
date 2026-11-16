<div class="contents">
    @if ($capacityTotals !== null && ($capacityTotals->personWeeks > 0 || $capacityTotals->needsEstimate > 0))
        @include('livewire.projects.partials.capacity-summary')
    @endif
</div>
