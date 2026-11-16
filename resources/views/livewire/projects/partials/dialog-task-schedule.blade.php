<flux:modal wire:model.live.self="scheduling" :dismissible="false" class="w-full max-w-md" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'task-schedule-heading')">
    @if ($scheduleTask !== null)
        <form wire:submit="saveSchedule" class="space-y-5">
            <div class="space-y-1"><p class="num text-xs text-muted-foreground">{{ $scheduleTask->code }}</p><flux:heading level="2" size="lg" id="task-schedule-heading">Schedule task</flux:heading><p class="break-words text-sm text-muted-foreground">{{ $scheduleTask->title }}</p></div>
            <div class="space-y-2"><flux:label for="schedule-start">Start date</flux:label><x-kit.date-field id="schedule-start" aria-label="Scheduled start date" wire:model="scheduleStart" /></div>
            @if ($canEditWork)
                <div class="space-y-2"><flux:label for="schedule-due">Due date</flux:label><x-kit.date-field id="schedule-due" aria-label="Scheduled due date" wire:model="scheduleDue" /></div>
            @else
                <dl><dt class="ppm-property-label">Due date</dt><dd class="mt-1 text-sm">{{ $scheduleTask->due_date ? \App\Livewire\Projects\ProjectsData::fmtDate($scheduleTask->due_date) : 'No deadline' }}</dd></dl>
            @endif
            <div class="flex items-center justify-end gap-2"><flux:button type="button" size="sm" variant="ghost" wire:click="$set('scheduling', false)">Cancel</flux:button><flux:button type="submit" size="sm" variant="primary">Save dates</flux:button></div>
        </form>
    @endif
</flux:modal>
