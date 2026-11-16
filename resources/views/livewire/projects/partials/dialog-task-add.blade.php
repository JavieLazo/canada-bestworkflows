{{-- The add-task dialog (add-task-dialog.tsx) — bound to Show's $adding and the task* draft properties. Included by show.blade.php and shares that view's variables. --}}
@php
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
@endphp
{{-- add-task-dialog.tsx --}}
<flux:modal wire:model.self="adding" class="max-h-[90vh] w-full max-w-lg overflow-y-auto">
    <div class="space-y-4">
        <div>
            <flux:heading size="lg">Add Task</flux:heading>
            <flux:text class="mt-1">
                Hand out a unit of work on {{ $project->name }}. It stays pending until an assignee picks it
                up from My Tasks; a start date only plans when that should be, and never stops anyone starting
                early. With two or more assignees, choose whether they hand in one team response or each their
                own.
            </flux:text>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-2 sm:col-span-2">
                <flux:label for="task-title">Title</flux:label>
                <flux:input
                    id="task-title"
                    placeholder="What has to be done"
                    wire:model.live.debounce.250ms="taskTitle"
                />
            </div>

            <div class="space-y-2 sm:col-span-2">
                <flux:label for="task-description">Description</flux:label>
                <flux:textarea
                    id="task-description"
                    rows="3"
                    placeholder="Scope, acceptance criteria, anything the assignee needs"
                    wire:model="taskDescription"
                />
            </div>

            <div class="space-y-2 sm:col-span-2">
                <flux:label>Assignees</flux:label>
                {{-- Entangled list; the nonce remounts the checklist (clean
                     search, fresh list) every time the draft resets.

                     `.live` on purpose: a nested wire:model is DEFERRED — each
                     tick is a request on the child only, and the new array is
                     pushed into the parent's client-side state without a
                     parent render. The "Add task" button's disabled gate
                     below is evaluated server-side, so without `.live` it
                     would go stale until some other parent round-trip. Live,
                     the child root's `wire:model.live="$parent.taskAssigneeIds"`
                     commits the parent after every toggle and the gate
                     re-evaluates (the server rule is the same either way). --}}
                <livewire:kit.employee-checklist
                    wire:model.live="taskAssigneeIds"
                    name="task-assignees"
                    placeholder="Search employees"
                    aria-label="Assignees"
                    :key="'task-assignees-'.$taskDraftNonce"
                />
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
                                :description="ProjectsData::SUBMISSION_MODE_HELP[$mode]"
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
                    >
                        <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3'])</x-slot:icon>
                        Attach
                    </flux:button>
                    <input
                        type="file"
                        multiple
                        class="hidden"
                        x-ref="briefPickInput"
                        wire:model="briefPick"
                    />
                </div>

                @if (count($briefStage) === 0)
                    <p class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                        Nothing attached. Anything you add here is stored with the task once it is created.
                    </p>
                @else
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
                                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'trash-2', 'class' => 'size-3.5 text-danger'])</x-slot:icon>
                                </flux:button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="flex items-end sm:col-span-2">
                <flux:button
                    variant="primary"
                    wire:click="saveTask"
                    :disabled="trim($taskTitle) === '' || count($taskAssigneeIds) === 0"
                >
                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'plus', 'class' => 'size-4'])</x-slot:icon>
                    Add Task
                </flux:button>
            </div>
        </div>
    </div>
</flux:modal>
