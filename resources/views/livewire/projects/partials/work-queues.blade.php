<div class="flex min-w-0 items-center gap-1 overflow-x-auto" role="group" aria-label="Work queues">
    @foreach (['ALL' => [$allLabel, null], 'REVIEW' => ['Review', $reviewCount], 'OVERDUE' => ['Overdue', $overdueCount], 'DUE_SOON' => ['Due soon', $dueSoonCount], 'UNASSIGNED' => ['Unassigned', $unassignedCount]] as $value => [$label, $count])
        <button type="button" class="ppm-attention" wire:click="setQueue('{{ $value }}')" aria-pressed="{{ $queue === $value ? 'true' : 'false' }}">
            {{ $label }}
            @if ($count > 0)<span class="ppm-count">{{ $count }}</span>
@endif
        </button>
    @endforeach
</div>
