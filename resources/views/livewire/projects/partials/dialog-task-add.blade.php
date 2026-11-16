
@php
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
@endphp
{{-- add-task-dialog.tsx --}}
<x-work.task-panel creating wire:model.live.self="adding" :title="$taskParentId === null ? 'New task' : 'New subtask'">
    @if ($creatingParent !== null)
        <x-slot:meta><flux:icon.arrow-turn-down-right class="size-3" /><span class="max-w-96 truncate" title="{{ $creatingParent->title }}">{{ $creatingParent->code }} · {{ $creatingParent->title }}</span></x-slot:meta>
    @endif
    {{-- A hidden assignee chooser must not join unrelated board requests. --}}
    @if ($adding)
    <form wire:submit="saveTask" class="space-y-5" x-data>
        <div class="flex items-center gap-2 text-xs text-muted-foreground"><span>Status</span><x-work.task-state :status="$taskCreateStatus" /></div>
        @if ($requestedTaskStatus !== $taskCreateStatus)<p class="text-xs text-muted-foreground">{{ in_array($requestedTaskStatus, ['SUBMITTED', 'RETURNED'], true) ? 'New tasks start Pending here. Submission and return need a recorded response and review.' : 'Your project lead sets this status. New tasks start Pending.' }}</p>
        @endif
        <flux:error name="taskCreation" />
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-2 sm:col-span-2">
                <flux:input
                    label="Title"
                    id="task-title"
                    placeholder="What has to be done"
                    wire:model="taskTitle"
                    maxlength="{{ TaskPolicy::TITLE_LIMIT }}"
                    required
                />
            </div>

            <div class="space-y-2 sm:col-span-2">
                <flux:textarea
                    label="Description"
                    id="task-description"
                    rows="3"
                    placeholder="Scope, acceptance criteria, anything the assignee needs"
                    wire:model="taskDescription"
                    maxlength="{{ TaskPolicy::DESCRIPTION_LIMIT }}"
                />
            </div>

            <div class="space-y-2 sm:col-span-2">
                @if ($taskParentId !== null)
                    <flux:label>Activity</flux:label>
                    @if ($creatingParent?->group_name !== null)<x-work.task-group :task="$creatingParent" />
                    @else<span class="text-sm text-muted-foreground">No activity</span>
                    @endif
                @else
                <x-kit.select label="Activity" aria-label="Activity" wire:model="taskGroupId" searchable>
                    <flux:select.option value="NONE">No activity</flux:select.option>
                    @foreach ($groups as $taskGroup)
                        <flux:select.option value="{{ $taskGroup->id }}">{{ $taskGroup->name }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                @endif
            </div>

            <div class="space-y-2 sm:col-span-2">
                <flux:label>Assignees</flux:label>
                {{-- The live selection shows the response mode as soon as a second person joins. --}}
                @if (! $canManage)
                    <livewire:projects.project-employee-checklist :project-id="$project->id" wire:model.live="taskAssigneeIds" name="task-assignees" placeholder="Search employees" aria-label="Assignees" :key="'task-assignees-'.$taskDraftNonce" />
                @elseif ($project->client_id !== null)
<livewire:projects.client-employee-checklist
                    :client-id="$project->client_id" wire:model.live="taskAssigneeIds"
                    name="task-assignees"
                    placeholder="Search employees"
                    aria-label="Assignees"
                    :key="'task-assignees-'.$taskDraftNonce"
                />
@else
<livewire:kit.employee-checklist
                    wire:model.live="taskAssigneeIds"
                    name="task-assignees"
                    placeholder="Search employees"
                    aria-label="Assignees"
                    :key="'task-assignees-'.$taskDraftNonce"
                />
@endif
            </div>

            {{-- The mode means something only with two or more people, so it
                 appears once the (live) checklist holds two; with fewer,
                 CreateTask stores GROUP whatever this says. --}}
            @if (count($taskAssigneeIds) >= 2)
                <div class="sm:col-span-2">
                    <flux:radio.group wire:model="taskSubmissionMode" label="How do assignees submit?">
                        @foreach (TaskPolicy::MODES as $mode => $modeLabel)
                            <flux:radio
                                value="{{ $mode }}"
                                :label="$modeLabel"
                            />
                        @endforeach
                    </flux:radio.group>
                </div>
            @endif

            <div class="space-y-2 sm:col-span-2">
                <flux:label>Priority</flux:label>
                <x-kit.select aria-label="Priority" wire:model="taskPriority">
                    @foreach (TaskPolicy::PRIORITIES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            </div>

            {{-- The planned start (optional): CreateTask keeps it inside the
                 project's dates and on or before the due date. --}}
            <div class="space-y-2">
                <flux:label for="task-start">Start Date</flux:label>
                <x-kit.date-field id="task-start" aria-label="Start date" wire:model="taskStartDate" />
            </div>

            <div class="space-y-2">
                <flux:label for="task-due">Due Date</flux:label>
                <x-kit.date-field id="task-due" aria-label="Due date" wire:model="taskDueDate" />
            </div>

            <div class="space-y-2 sm:col-span-2">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        Attach brief (optional)
                    </p>
                    <flux:button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-7"
                        x-on:click="$refs.briefPickInput.click()"
                        :disabled="in_array($taskCreateStatus, ['COMPLETED', 'CANCELLED'], true)"
                    >
                        <x-slot:icon>
@include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3'])</x-slot:icon>
                        Attach
                    </flux:button>
                    <input
                        type="file"
                        multiple
                        class="hidden"
                        x-ref="briefPickInput"
                        wire:model="briefPick"
                        @disabled(in_array($taskCreateStatus, ['COMPLETED', 'CANCELLED'], true))
                    />
                </div>

                @if (count($briefStage) > 0)
                    <ul class="space-y-1">
                        @foreach ($briefStage as $i => $staged)
                            <li
                                wire:key="staged-{{ $i }}-{{ $staged->getClientOriginalName() }}"
                                class="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2"
                            >
                                @include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3.5 shrink-0 text-muted-foreground'])
                                <span class="min-w-0 flex-1 truncate text-sm">{{ $staged->getClientOriginalName() }}</span>
                                <span class="num shrink-0 text-xs text-muted-foreground">
                                    {{ ProjectsData::humanSize((int) $staged->getSize()) }}
                                </span>
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    square
                                    class="h-7 w-7 shrink-0"
                                    aria-label="Remove {{ $staged->getClientOriginalName() }}"
                                    wire:click="removeBrief({{ $i }})"
                                >
                                    <x-slot:icon>
@include('livewire.projects.partials.icon', ['icon' => 'trash-2', 'class' => 'size-3.5 text-danger'])</x-slot:icon>
                                </flux:button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="flex items-end sm:col-span-2">
                <flux:button
                    variant="primary"
                    type="submit"
                >
                    <x-slot:icon>
@include('livewire.projects.partials.icon', ['icon' => 'plus', 'class' => 'size-4'])</x-slot:icon>
                    {{ $taskParentId === null ? 'Add task' : 'Add subtask' }}
                </flux:button>
            </div>
        </div>
    </form>
    @endif
</x-work.task-panel>
