@if ($canEditWork && ! $openTask->isSubtask())
    <x-kit.select size="sm" label="Activity" aria-label="Move task to activity" wire:change="moveTaskGroup($event.target.value)">
        <flux:select.option value="NONE" :selected="$openTask->group_id === null">No activity</flux:select.option>
        @foreach ($groups as $taskGroup)
            <flux:select.option value="{{ $taskGroup->id }}" :selected="$openTask->group_id === $taskGroup->id">{{ $taskGroup->name }}</flux:select.option>
        @endforeach
    </x-kit.select>
@else
    <x-work.task-group :task="$openTask" />
@endif
