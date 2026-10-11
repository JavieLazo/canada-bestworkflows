{{-- The task-review dialog (task-review-dialog.tsx) — bound to Show's $reviewOpen, drawn from $openTask and its companions. Included by show.blade.php and shares that view's variables.

     DELIBERATE DIVERGENCE: an INDIVIDUAL task has no single hand-in to
     accept or return. Its members' responses are listed one per assignee
     (partials/task-responses) and decided one by one; the task itself
     offers "Complete task", disabled until every current assignee's
     response is approved, and Cancel. A GROUP task uses the reference
     dialog.

     DELIBERATE DIVERGENCE: whoever runs the project — a Projects
     manager or its Task Leader ($runsProject) — reviews it, and may also set
     a status by hand ("Set status", ManualTaskStatus). A leader who is not a
     manager edits only the start date in the edit panel. --}}
@php
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
@endphp
{{-- task-review-dialog.tsx --}}
<flux:modal wire:model.self="reviewOpen" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto">
    @if ($openTask !== null)
        @php
            // The task's own mode picks the map. Only someone who runs the
            // project (a manager or its Task Leader) off the crew may accept,
            // return or complete; a manager on it keeps Cancel, a leader on it
            // decides nothing, and anyone else gets no decision at all — the
            // same refusals ReviewTask makes.
            $actions = TaskPolicy::managerCanMoveTo($openTask->status, $openTask->submission_mode);
            if (! $openTaskMayReview) {
                $actions = $canManage ? array_values(array_intersect($actions, ['CANCELLED'])) : [];
            }
            $individual = $openTask->isIndividual();
            $selfReview = $runsProject && $openTaskIsAssignee && ! TaskPolicy::isClosed($openTask->status);
            // A Task Leader who is not a Projects manager plans the start
            // date and nothing else in the edit panel (UpdateTaskDetails).
            $leaderOnly = $runsProject && ! $canManage;
            // The remark box serves the decisions and "Set status" alike.
            $needsRemark = in_array('RETURNED', $actions, true) || in_array('RETURNED', $openTaskManualMoves, true);
            $taskOverdue = TaskPolicy::isOverdue(ProjectsData::taskShape($openTask), $today);
        @endphp
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ $openTask->code !== '' ? $openTask->code : 'Task' }}</flux:heading>
                <flux:text class="mt-1">{{ $openTask->title }}</flux:text>
            </div>

            <div class="flex flex-wrap items-center gap-3 rounded-md border border-border p-4">
                <span class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                    <span class="num text-sm font-semibold">
                        @if (count($openTaskAssignees) === 0)
                            —
                        @elseif (count($openTaskAssignees) === 1)
                            {{ ProjectsData::initials($openTaskAssignees[0]) }}
                        @else
                            {{ count($openTaskAssignees) }}
                        @endif
                    </span>
                </span>
                <div class="min-w-0">
                    <p class="break-words font-semibold">{{ ProjectsData::assigneeNames($openTask) }}</p>
                    <p class="num break-words text-xs text-muted-foreground">
                        @if (count($openTaskAssignees) === 0)
                            Unassigned
                        @else
                            @foreach ($openTaskAssignees as $assignee)
                                {{ \App\Support\EmployeeIdentity::meta(\App\Support\EmployeeIdentity::besideName($assignee), $assignee->position) ?: '—' }}{{ $loop->last ? '' : ',' }}
                            @endforeach
                        @endif
                    </p>
                    {{-- How the crew hands in, once there is a crew to speak of
                         (an INDIVIDUAL task keeps its mode below two people),
                         and how far an INDIVIDUAL task's responses have got. --}}
                    @if ($individual || count($openTaskAssignees) >= 2)
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="pill pill-muted">{{ $individual ? 'Individual responses' : 'Team response' }}</span>
                            @if ($individual)
                                <p class="num text-xs text-muted-foreground">{{ $openTask->responseSummary() }}</p>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="ml-auto flex items-center gap-2">
                    <span class="{{ TaskPolicy::priorityPill($openTask->priority) }}">
                        {{ TaskPolicy::priorityLabel($openTask->priority) }}
                    </span>
                    <span class="{{ TaskPolicy::statusPill($openTask->status) }}">{{ TaskPolicy::statusLabel($openTask->status) }}</span>
                    <flux:button
                        type="button"
                        variant="ghost"
                        size="sm"
                        square
                        aria-label="Edit task details and dates"
                        wire:click="toggleEditingDetails"
                    >
                        <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'edit-3', 'class' => 'size-4'])</x-slot:icon>
                    </flux:button>
                </div>
            </div>

            @if ($editingDetails)
                <div class="grid gap-4 rounded-md border border-border bg-muted/20 p-4 sm:grid-cols-3">
                    @if ($leaderOnly)
                        <p class="text-xs text-muted-foreground sm:col-span-3">
                            @if ($openTaskRunnable)
                                As Task Leader you can plan when this task starts. A Projects manager changes its
                                assignees, priority and due date.
                            @else
                                You're one of this task's assignees, so a Projects manager plans it.
                            @endif
                        </p>
                    @else
                        <div class="space-y-2 sm:col-span-3">
                            <flux:label>Assignees</flux:label>
                            {{-- `.live` for the reason in dialog-task-add: the mode
                                 choice below appears and disappears with the
                                 number ticked, and that is decided server-side. --}}
                            <livewire:kit.employee-checklist
                                wire:model.live="draftAssigneeIds"
                                name="review-assignees"
                                placeholder="Search employees"
                                aria-label="Assignees"
                                :key="'review-assignees-'.$openTask->id"
                            />
                        </div>
                        {{-- The mode may change only while nothing has been handed
                             in ($openTaskModeLocked, from the task's content-free
                             facts, so a manager on the crew sees the lock a peer's
                             evidence causes). UpdateTaskDetails re-checks it, and
                             saveDetails() posts the choice only if it moved. --}}
                        @if (! $openTaskModeLocked && count($draftAssigneeIds) >= 2)
                            <div class="sm:col-span-3">
                                <flux:radio.group wire:model="draftSubmissionMode" label="How do assignees submit?">
                                    @foreach (TaskPolicy::MODES as $mode => $modeLabel)
                                        <flux:radio
                                            value="{{ $mode }}"
                                            :label="$modeLabel"
                                            :description="ProjectsData::SUBMISSION_MODE_HELP[$mode]"
                                        />
                                    @endforeach
                                </flux:radio.group>
                            </div>
                        @elseif ($openTaskModeLocked && ($individual || count($draftAssigneeIds) >= 2))
                            <p class="text-xs text-muted-foreground sm:col-span-3">
                                {{ TaskPolicy::MODES[$openTask->submission_mode] ?? $openTask->submission_mode }} — the
                                submission type is locked once anyone has handed in work or attached evidence.
                            </p>
                        @endif
                        <div class="space-y-2">
                            <flux:label>Priority</flux:label>
                            <x-kit.select aria-label="Task priority" wire:model="draftPriority">
                                @foreach (TaskPolicy::PRIORITIES as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
                        </div>
                    @endif
                    @if (! $leaderOnly || $openTaskRunnable)
                        <div class="space-y-2">
                            <flux:label for="task-edit-start">Start date</flux:label>
                            <x-kit.date-field id="task-edit-start" aria-label="Start date" wire:model="draftStartDate" />
                        </div>
                    @endif
                    @unless ($leaderOnly)
                        <div class="space-y-2">
                            <flux:label for="task-edit-due">Due date</flux:label>
                            <x-kit.date-field id="task-edit-due" aria-label="Due date" wire:model="draftDueDate" />
                        </div>
                    @endunless
                    <div class="flex items-end justify-end gap-2 sm:col-span-3">
                        <flux:button type="button" variant="ghost" wire:click="$set('editingDetails', false)">
                            Cancel
                        </flux:button>
                        <flux:button type="button" variant="primary" wire:click="saveDetails">
                            <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'save', 'class' => 'size-4'])</x-slot:icon>
                            Save task details
                        </flux:button>
                    </div>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0 space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Project</p>
                    <div class="break-words text-sm font-medium">{{ $project->name }}</div>
                </div>
                <div class="min-w-0 space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Start</p>
                    <div class="break-words text-sm font-medium">
                        <span class="num">{{ $openTask->start_date ? ProjectsData::fmtDate($openTask->start_date) : '—' }}</span>
                    </div>
                </div>
                <div class="min-w-0 space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Due</p>
                    <div class="break-words text-sm font-medium">
                        @if ($openTask->due_date)
                            <span class="{{ $taskOverdue ? 'num text-danger' : 'num' }}">
                                {{ ProjectsData::fmtDate($openTask->due_date) }}{{ $taskOverdue ? ' · overdue' : '' }}
                            </span>
                        @else
                            —
                        @endif
                    </div>
                </div>
                <div class="min-w-0 space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Assigned by</p>
                    <div class="break-words text-sm font-medium">
                        {{ $accountName($openTask->getAttribute('assigned_by')) }}
                        <span class="num block text-xs font-normal text-muted-foreground">
                            {{ ProjectsData::fmtDateTime($openTask->assigned_at) }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="space-y-1">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Description</p>
                <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">
                    {{ trim($openTask->description ?? '') !== '' ? trim($openTask->description) : 'No description given.' }}
                </p>
            </div>

            {{-- Management owns the brief, so it stays editable at any status
                 in the dialog; outside the editable statuses the server
                 answers "Task evidence is locked in this status." --}}
            <div class="space-y-2">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Brief &amp; references</p>
                    <flux:button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-7"
                        x-on:click="$refs.reviewBriefInput.click()"
                    >
                        <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3'])</x-slot:icon>
                        Attach
                    </flux:button>
                    <input
                        type="file"
                        class="hidden"
                        x-ref="reviewBriefInput"
                        wire:model="reviewBriefUpload"
                    />
                </div>
                @if ($openTaskBrief->isEmpty())
                    <p class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                        No brief attached. Attach the spec or form the assignee should work from.
                    </p>
                @else
                    <ul class="space-y-1">
                        @foreach ($openTaskBrief as $f)
                            <li wire:key="brief-{{ $f->id }}" class="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2">
                                @include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3.5 shrink-0 text-muted-foreground'])
                                <span class="min-w-0 flex-1 truncate text-sm">{{ $f->file_name }}</span>
                                <span class="num shrink-0 text-xs text-muted-foreground">{{ ProjectsData::humanSize((int) $f->size) }}</span>
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    square
                                    class="h-7 w-7 shrink-0"
                                    href="{{ route('projects.task-files.download', $f->id) }}"
                                    aria-label="Download {{ $f->file_name }}"
                                >
                                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'download', 'class' => 'size-3.5'])</x-slot:icon>
                                </flux:button>
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    square
                                    class="h-7 w-7 shrink-0"
                                    aria-label="Remove {{ $f->file_name }}"
                                    wire:click="deleteFile('{{ $f->id }}')"
                                >
                                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'trash-2', 'class' => 'size-3.5 text-danger'])</x-slot:icon>
                                </flux:button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($individual)
                {{-- One response per assignee, each with its own note, files
                     and review — drawn only as far as this viewer may see. --}}
                @include('livewire.projects.partials.task-responses')
            @else
                {{-- Read-only on purpose: the reviewer must not be able to add to
                     or thin out what the assignee actually handed in. --}}
                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Submitted files</p>
                    </div>
                    @if ($openTaskSubmitted->isEmpty())
                        <p class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                            No files were submitted with this task.
                        </p>
                    @else
                        <ul class="space-y-1">
                            @foreach ($openTaskSubmitted as $f)
                                <li wire:key="submission-{{ $f->id }}" class="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2">
                                    @include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3.5 shrink-0 text-muted-foreground'])
                                    <span class="min-w-0 flex-1 truncate text-sm">{{ $f->file_name }}</span>
                                    <span class="num shrink-0 text-xs text-muted-foreground">{{ ProjectsData::humanSize((int) $f->size) }}</span>
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        square
                                        class="h-7 w-7 shrink-0"
                                        href="{{ route('projects.task-files.download', $f->id) }}"
                                        aria-label="Download {{ $f->file_name }}"
                                    >
                                        <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'download', 'class' => 'size-3.5'])</x-slot:icon>
                                    </flux:button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Returning clears submitted_at, so gate on the remarks too —
                     the assignee's note is still the last thing they said. --}}
                @if ($openTask->submitted_at !== null || trim($openTask->remarks ?? '') !== '')
                    <div class="space-y-1">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">
                            @php
                                // Who handed it in: the recorded submitter when the
                                // task carries one, else the assignee set.
                                $submittedBy = $openTask->submitter !== null
                                    ? ProjectsData::employeeName($openTask->submitter)
                                    : ProjectsData::assigneeNames($openTask);
                            @endphp
                            @if ($openTask->submitted_at !== null)
                                Submitted <span class="num">{{ ProjectsData::fmtDateTime($openTask->submitted_at) }}</span>
                            @else
                                Last submission
                            @endif
                            @if ($submittedBy !== '—')
                                by {{ $submittedBy }}
                            @endif
                        </p>
                        <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">
                            {{ trim($openTask->remarks ?? '') !== '' ? trim($openTask->remarks) : 'No remarks from the assignees.' }}
                        </p>
                    </div>
                @endif
            @endif

            @if ($openTask->review_remarks)
                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">
                        Review remarks{{ $openTask->getAttribute('reviewed_by') !== null ? ' · '.$accountName($openTask->getAttribute('reviewed_by')) : '' }}
                    </p>
                    <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">
                        {{ $openTask->review_remarks }}
                    </p>
                </div>
            @endif

            @if (count($actions) > 0 || $openTaskManualMoves !== [])
                <div class="space-y-2">
                    {{-- A review remark when there is a decision to make; for
                         "Set status" alone (a crew member, say) it is a note. --}}
                    <flux:label for="task-review-remark">
                        {{ count($actions) > 0 ? 'Review remark' : 'Status note' }}{{ $needsRemark ? ' (required to return)' : '' }}
                    </flux:label>
                    <flux:textarea
                        id="task-review-remark"
                        rows="3"
                        placeholder="What the assignee should know about this decision"
                        wire:model="remarks"
                    />
                </div>
            @endif

            {{-- Above the decision row, and deliberately not gated on
                 `actions` — a note is still legitimate once the task is
                 closed (task-review-dialog.tsx:840-856). --}}
            <div class="space-y-2">
                <p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    @include('livewire.projects.partials.icon', ['icon' => 'message-square', 'class' => 'size-3'])
                    Discussion
                    @if ($openTaskComments->count() > 0)
                        <span class="num">({{ $openTaskComments->count() }})</span>
                    @endif
                </p>

                @if ($openTaskComments->isEmpty())
                    <p class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                        No comments yet.
                    </p>
                @else
                    <ul class="space-y-2">
                        @foreach ($openTaskComments as $c)
                            <li
                                wire:key="comment-{{ $c->id }}"
                                class="rounded-md border px-3 py-2 {{ $c->side === 'MANAGEMENT' ? 'border-border bg-muted/30' : 'border-border bg-card' }}"
                            >
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                    <span class="text-sm font-medium">{{ $c->author_name }}</span>
                                    <span class="num text-xs text-muted-foreground">
                                        {{ ProjectsData::fmtDateTime($c->created_at) }}
                                    </span>
                                </div>
                                @if ($c->event)
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        Posted with {{ mb_strtolower(TaskPolicy::statusLabel($c->event)) }}
                                    </p>
                                @endif
                                <p class="mt-1 whitespace-pre-wrap break-words text-sm">{{ $c->body }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="space-y-2">
                    <flux:textarea
                        aria-label="Add a comment"
                        placeholder="Explain what needs changing, or answer a question…"
                        rows="2"
                        wire:model="commentBody"
                    />
                    <div class="flex justify-end">
                        <flux:button type="button" size="sm" variant="primary" wire:click="postComment">
                            <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'send', 'class' => 'size-3'])</x-slot:icon>
                            Post comment
                        </flux:button>
                    </div>
                </div>
            </div>

            @if ($selfReview)
                <p class="rounded-md border border-border bg-muted/30 p-3 text-sm text-muted-foreground">
                    You're one of this task's assignees, so another reviewer has to decide it.
                </p>
            @endif

            {{-- A status set by hand (ManualTaskStatus), for whoever runs the
                 project: only the moves its safeguards allow from the status
                 shown, which rides along so a task moved meanwhile is refused.
                 The remark above goes with it, to the thread and the crew. --}}
            @if ($openTaskManualMoves !== [])
                <div class="flex flex-wrap items-end justify-between gap-3 border-t border-border pt-4">
                    <div class="space-y-2">
                        <flux:label for="task-manual-status">Set status by hand</flux:label>
                        <x-kit.select id="task-manual-status" aria-label="New task status" class="w-[13rem]" wire:model="manualStatus">
                            <flux:select.option value="">Choose a status</flux:select.option>
                            @foreach ($openTaskManualMoves as $move)
                                <flux:select.option value="{{ $move }}">{{ TaskPolicy::statusLabel($move) }}</flux:select.option>
                            @endforeach
                        </x-kit.select>
                    </div>
                    <flux:button type="button" variant="outline" wire:click="setTaskStatus('{{ $openTask->status }}')">
                        Set status
                    </flux:button>
                </div>
            @endif

            @if (count($actions) > 0)
                <div class="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                    @if (in_array('CANCELLED', $actions, true))
                        <flux:button variant="danger" wire:click="review('CANCELLED')">
                            <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'ban', 'class' => 'size-4'])</x-slot:icon>
                            Cancel task
                        </flux:button>
                    @endif
                    @if (in_array('RETURNED', $actions, true))
                        <flux:button variant="outline" wire:click="review('RETURNED')">
                            <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'undo-2', 'class' => 'size-4'])</x-slot:icon>
                            Return for rework
                        </flux:button>
                    @endif
                    @if (in_array('COMPLETED', $actions, true) && $individual)
                        {{-- Disabled, with the reason beside it, until every
                             current assignee's response is approved; ReviewTask
                             re-checks that gate under the task lock. --}}
                        <flux:button
                            variant="primary"
                            wire:click="review('COMPLETED')"
                            :disabled="$openTaskGaps !== [] || $openTaskWaitingOn !== ''"
                            aria-describedby="complete-reason"
                        >
                            <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'check', 'class' => 'size-4'])</x-slot:icon>
                            Complete task
                        </flux:button>
                    @elseif (in_array('COMPLETED', $actions, true))
                        <flux:button variant="primary" wire:click="review('COMPLETED')">
                            <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'check', 'class' => 'size-4'])</x-slot:icon>
                            Accept task
                        </flux:button>
                    @endif
                </div>
                @if (in_array('COMPLETED', $actions, true) && $individual)
                    <p id="complete-reason" class="text-right text-xs text-muted-foreground">
                        {{ $openTaskWaitingOn !== '' ? $openTaskWaitingOn : 'Every response is approved.' }}
                    </p>
                @endif
            @endif
        </div>
    @endif
</flux:modal>
