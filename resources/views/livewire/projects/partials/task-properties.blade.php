@php
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
    $propertyKey = 'task-'.$openTask->id;
@endphp
<dl class="ppm-property-grid" aria-label="Task properties">
    <div class="ppm-property-row"><dt>Status</dt><dd>
        @if ($openTaskManualMoves !== [])
            <x-work.popover :id="$propertyKey.'-status'" label="Change task status">
                <x-slot:trigger><flux:button x-ref="trigger" size="sm" variant="ghost" :popovertarget="$propertyKey.'-status'" aria-haspopup="dialog"><x-work.task-state :status="$openTask->status" /></flux:button></x-slot:trigger>
                @if ($taskStatusConfirm)
                    <span class="text-sm font-medium">{{ TaskPolicy::statusLabel($manualStatus) }}</span>
                    <flux:textarea wire:model="remarks" :label="$manualStatus === 'RETURNED' ? 'Reason for return' : 'Note (optional)'" rows="2" />
                    <div class="flex justify-end gap-2"><flux:button size="sm" variant="ghost" wire:click="$set('taskStatusConfirm', false)">Back</flux:button><flux:button size="sm" variant="primary" wire:click="setTaskStatus('{{ $seenTaskStatus }}')">Apply status</flux:button></div>
                @else
                    @foreach ($openTaskManualMoves as $move)<flux:button size="sm" variant="ghost" class="w-full justify-start" wire:click="chooseTaskStatus('{{ $move }}')"><x-work.task-state :status="$move" /></flux:button>
                    @endforeach
                @endif
            </x-work.popover>
        @else<x-work.task-state :status="$openTask->status" />
        @endif
    </dd></div>
    <div class="ppm-property-row"><dt>Priority</dt><dd>
        @if ($canEdit && ! $leaderOnly)<flux:dropdown><flux:button size="sm" variant="ghost" icon="flag" :aria-label="'Task priority: '.TaskPolicy::priorityLabel($openTask->priority)">{{ TaskPolicy::priorityLabel($openTask->priority) }}</flux:button><flux:menu>@foreach (TaskPolicy::PRIORITIES as $value => $label)<flux:menu.item :icon="$openTask->priority === $value ? 'check' : null" wire:click="saveTaskProperty('priority', '{{ $value }}')">{{ $label }}</flux:menu.item>@endforeach</flux:menu></flux:dropdown>
        @else<span class="text-xs">{{ TaskPolicy::priorityLabel($openTask->priority) }}</span>
        @endif
    </dd></div>
    <div class="ppm-property-row"><dt>Assignees</dt><dd>
        @if ($canEdit && ! $leaderOnly && ($canManage || ! $openTaskModeLocked))
            <x-work.popover :id="$propertyKey.'-assignees'" label="Task assignees">
                <x-slot:trigger><flux:button x-ref="trigger" size="sm" variant="ghost" :popovertarget="$propertyKey.'-assignees'" aria-haspopup="dialog" class="max-w-full"><x-work.assignees :task="$openTask" summary /></flux:button></x-slot:trigger>
                @if (! $canManage)<livewire:projects.project-employee-checklist :project-id="$project->id" :task-id="$openTask->id" wire:model.live="draftAssigneeIds" name="review-assignees" placeholder="Search employees" aria-label="Assignees" :key="'review-assignees-'.$openTask->id.'-'.$reviewAssigneeNonce" />
                @elseif ($project->client_id !== null)<livewire:projects.client-employee-checklist :client-id="$project->client_id" :task-id="$openTask->id" wire:model.live="draftAssigneeIds" name="review-assignees" placeholder="Search employees" aria-label="Assignees" :key="'review-assignees-'.$openTask->id.'-'.$reviewAssigneeNonce" />
                @else<livewire:kit.employee-checklist wire:model.live="draftAssigneeIds" name="review-assignees" placeholder="Search employees" aria-label="Assignees" :key="'review-assignees-'.$openTask->id.'-'.$reviewAssigneeNonce" />
                @endif
                <div class="flex justify-end gap-2"><flux:button size="sm" variant="ghost" :popovertarget="$propertyKey.'-assignees'" popovertargetaction="hide" wire:click="resetDetails">Cancel</flux:button><flux:button size="sm" variant="primary" wire:click="saveTaskProperty('assignees')">Apply</flux:button></div>
            </x-work.popover>
        @else<x-work.assignees :task="$openTask" summary />
        @endif
    </dd></div>
    @foreach (['start' => 'Start date', 'due' => 'Due date'] as $field => $label)
        @php($date = $field === 'start' ? $openTask->start_date : $openTask->due_date)
        <div class="ppm-property-row"><dt>{{ $label }}</dt><dd>
            @if ($canEdit && ($field === 'start' || ! $leaderOnly))
                <x-work.popover :id="$propertyKey.'-'.$field" :label="$label">
                    <x-slot:trigger><flux:button x-ref="trigger" size="sm" variant="ghost" icon="calendar-days" :popovertarget="$propertyKey.'-'.$field" aria-haspopup="dialog">{{ $date ? ProjectsData::fmtDate($date) : 'Not set' }}</flux:button></x-slot:trigger>
                    <x-kit.date-field :aria-label="$label" wire:model="{{ $field === 'start' ? 'draftStartDate' : 'draftDueDate' }}" />
                    <div class="flex justify-end gap-2"><flux:button size="sm" variant="ghost" :popovertarget="$propertyKey.'-'.$field" popovertargetaction="hide" wire:click="resetDetails">Cancel</flux:button><flux:button size="sm" variant="primary" wire:click="saveTaskProperty('{{ $field }}')">Apply</flux:button></div>
                </x-work.popover>
            @else<span class="num text-xs">{{ $date ? ProjectsData::fmtDate($date) : '—' }}</span>
            @endif
        </dd></div>
    @endforeach
    <div class="ppm-property-row"><dt>Project</dt><dd><a class="block truncate text-xs hover:text-primary" href="{{ route('projects.show', $project->id) }}" title="{{ $project->name }}">{{ $project->name }}</a></dd></div>
    <div class="ppm-property-row"><dt>Activity</dt><dd>
        @if ($canEditWork && ! $openTask->isSubtask())
            <x-work.popover :id="$propertyKey.'-group'" label="Move task to activity">
                <x-slot:trigger><flux:button x-ref="trigger" size="sm" variant="ghost" icon="folder" :popovertarget="$propertyKey.'-group'" aria-haspopup="dialog"><span class="truncate" title="{{ $openTask->group_name ?? 'No activity' }}">{{ $openTask->group_name ?? 'No activity' }}</span></flux:button></x-slot:trigger>
                @include('livewire.projects.partials.task-group-picker')
            </x-work.popover>
        @elseif ($openTask->group_name !== null)<x-work.task-group :task="$openTask" />
        @else<span>No activity</span>
        @endif
    </dd></div>
    @if ($individual || count($draftAssigneeIds) >= 2)
        <div class="ppm-property-row"><dt>Responses</dt><dd>
            @if ($canEdit && ! $leaderOnly && ! $openTaskModeLocked)
                <flux:dropdown><flux:button size="sm" variant="ghost" icon="users" :aria-label="'How do assignees submit? '.($individual ? 'Individual' : 'One team response')">{{ $individual ? 'Individual' : 'One team response' }}</flux:button><flux:menu><flux:menu.item wire:click="saveTaskProperty('mode', 'GROUP')">One team response</flux:menu.item><flux:menu.item wire:click="saveTaskProperty('mode', 'INDIVIDUAL')">Each assignee</flux:menu.item></flux:menu></flux:dropdown>
            @else<span class="inline-flex items-center gap-1.5 text-xs" title="The submission type is locked once anyone has handed in work or attached evidence."><flux:icon.lock-closed class="size-3 text-muted-foreground" />{{ $individual ? 'Individual' : 'One team response' }}</span>
            @endif
        </dd></div>
    @endif
</dl>
