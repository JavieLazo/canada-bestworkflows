{{--
    The task detail dialog — port of my-tasks/task-detail-dialog.tsx.

    Rendered from the My Tasks page component; everything here reads the
    variables its render() passed. The modal element always exists so
    Flux::modal('task-detail')->show() has something to open; the body renders
    only while a task is open.

    A task is shared by 0..N assignees (project_task_assignees): $coAssignees
    names the peers. On a GROUP task $submittedByName is set when the last
    submission was a peer's rather than the actor's, and the SUBMISSION list
    is the whole crew's ("Submission files", each peer's file named for its
    uploader) — status, remarks, submitted_at and the evidence are
    one-per-task, so "your" must not be assumed.

    DELIBERATE DIVERGENCE: on an INDIVIDUAL task everything the actor hands
    in is their OWN response. The hint, the rework box and the
    closed-state line key on $myState (their response's state while the task
    is in progress — on a GROUP task simply the task's status); the note and
    the review shown are $submissionNote / $reviewNote, the actor's own; the
    SUBMISSION list is the actor's own evidence only (WorkRecords::files()
    leaves a peer's out, and WorkRecords::download() refuses it); and
    $coAssignees names each peer with their response STATE, never their
    content. The closed-state line has one arm per state that ends the
    actor's part, and NO catch-all: a member who still owes a response gets
    the submit form instead, never "Management cancelled this task".

    Divergence noted: the source's Radix dialog scrolled its own content
    (max-h-[90vh] overflow-y-auto on DialogContent); here that sits on a
    wrapper div inside the Flux modal, which has no free-tier equivalent prop.
--}}
@php
    use App\Domain\Projects\TaskPolicy;
    use App\Support\ManilaClock;

    // Keyed on the actor's own state: an INDIVIDUAL member reaches
    // IN_PROGRESS here only while they have no response yet.
    $hint = $open ? match ($myState) {
        'PENDING' => 'Start the task when you begin working on it.',
        'IN_PROGRESS' => $individual ? 'Submit your response once your part is done.' : 'Submit it for review once the work is done.',
        'RETURNED' => $individual ? 'Management sent your response back — update it and resubmit.' : 'Management sent this back — pick it up again and resubmit.',
        'SUBMITTED' => $individual ? 'Your response is waiting for management review.' : 'Submitted and waiting for management review.',
        'APPROVED' => "Your response was approved. The task completes once everyone's response is approved.",
        'CANCELLED' => 'This task was called off.',
        default => 'This task is closed.',
    } : '';

    $returned = $myState === 'RETURNED';
@endphp
<x-work.task-panel name="task-detail" :title="$open?->title ?? 'Task'" :code="$open?->code" wire:close="dialogClosed">
    @if ($open)
        <x-slot:headingActions>
            @if ($canStart)<flux:button size="sm" variant="primary" icon="play" wire:click="startTask">{{ $open->status === 'RETURNED' ? 'Resume task' : 'Start task' }}</flux:button>
            @elseif ($canSubmit)<flux:button size="sm" variant="primary" icon="paper-airplane" x-on:click="const section = $el.closest('dialog').querySelector('[data-task-response]'); section?.scrollIntoView({ block: 'start' }); section?.querySelector('textarea')?.focus({ preventScroll: true })">Respond</flux:button>
            @endif
        </x-slot:headingActions>
        <x-slot:meta><span class="max-w-72 truncate">{{ $open->project?->name ?? 'Task' }}</span></x-slot:meta>
        <div class="space-y-5">
            @if ($open->parent !== null)<button type="button" wire:click="openTask('{{ $open->parent->id }}')" class="block w-full rounded text-left focus-visible:outline-2 focus-visible:outline-primary"><x-work.task-parent :task="$open" compact /></button>
            @else<x-work.task-parent :task="$open" compact />
            @endif
            <dl class="ppm-property-grid" aria-label="Task properties">
                <div class="ppm-property-row"><dt>Status</dt><dd class="flex items-center gap-1"><x-work.task-state :status="$open->status" /><flux:tooltip :content="$hint"><flux:button size="sm" variant="ghost" square icon="information-circle" aria-label="About this task status" /></flux:tooltip></dd></div>
                <div class="ppm-property-row"><dt>Priority</dt><dd class="text-xs">{{ TaskPolicy::priorityLabel($open->priority) }}</dd></div>
                <div class="ppm-property-row"><dt>Project</dt><dd class="truncate text-xs" title="{{ $open->project?->name }}">{{ $open->project?->name ?? '—' }}</dd></div>
                <div class="ppm-property-row"><dt>Activity</dt><dd>@if ($open->group_name !== null)<x-work.task-group :task="$open" />@else<span>No activity</span>@endif</dd></div>
                <div class="ppm-property-row"><dt>Start</dt><dd class="num text-xs">{{ $open->start_date?->format('M d, Y') ?? 'No start date' }}</dd></div>
                <div class="ppm-property-row"><dt>Due</dt><dd>@if ($open->due_date)<x-work.task-deadline :task="$open" :today="$today" own />@else<span class="text-xs">No due date</span>@endif</dd></div>
                @if ($individual && $myResponse !== null)<div class="ppm-property-row" role="group" aria-label="Your response: {{ $myResponse->label() }}"><dt>Your response</dt><dd><x-work.task-state :status="$myResponse->status" :label="$myResponse->label()" /></dd></div>
                @endif
            </dl>
            <details class="text-xs text-muted-foreground"><summary class="w-fit cursor-pointer">Assignment</summary><dl class="mt-3 grid grid-cols-2 gap-3"><div><dt>Assigned by</dt><dd class="mt-1 text-foreground">{{ $assignedBy ?? '—' }}</dd></div><div><dt>Assigned</dt><dd class="num mt-1 text-foreground">{{ $open->assigned_at?->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A') ?? '—' }}</dd></div></dl>
                @if ($coAssignees !== '')<p class="mt-3">Working with: <span class="text-foreground">{{ $coAssignees }}</span></p>@endif
            </details>
            @if ($returned)
                <section class="space-y-2" aria-label="Returned for Rework"><h3 class="ppm-section-title text-danger">Returned for Rework</h3><p class="text-sm">{{ trim((string) $reviewNote) !== '' ? $reviewNote : 'No remarks were left with the return.' }}</p></section>
            @endif
            <section class="space-y-2"><h3 class="ppm-section-title">Description</h3><p class="whitespace-pre-line break-words text-sm text-muted-foreground">{{ trim((string) $open->description) !== '' ? $open->description : 'No description given.' }}</p></section>
            @if (! $open->isSubtask() && $open->has_subtasks)
                <section class="space-y-3" aria-label="Your subtasks"><h3 class="ppm-section-title">Your subtasks</h3><x-work.subtask-progress :task="$open" /><x-work.subtask-list :rows="$openSubtasks" :today="$today" /></section>
            @endif
            @include('livewire.tasks.partials.task-attachments', [
                'kind' => 'BRIEF', 'label' => 'Brief from management',
                'files' => $openFiles->where('kind', 'BRIEF')->values(),
                'canUpload' => false, 'canDelete' => false,
                'emptyLabel' => 'No brief was attached to this task.',
            ])

            @if (trim((string) $submissionNote) !== '')
                <div class="space-y-1">
                    {{-- A GROUP note is per task, not per assignee — say whose it
                         was. An INDIVIDUAL one is only ever the actor's own. --}}
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">
                        @if ($individual)
                            Your response
                        @else
                            {{ $submittedByName !== null ? 'Last submission by '.$submittedByName : 'Your last submission' }}
                        @endif
                    </p>
                    <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">{{ $submissionNote }}</p>
                </div>
            @endif

            {{-- The rework note has to survive "Resume task" — it is what the
                 employee is working against until they resubmit. --}}
            @if (! $returned && trim((string) $reviewNote) !== '')
                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Review Remarks</p>
                    <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">{{ $reviewNote }}</p>
                </div>
            @endif

            {{-- Attaching the evidence and describing it are one action, so the
                 files sit directly on top of the note the reviewer reads. --}}
            @if ($canSubmit)
                <section data-task-response class="space-y-4">
                @include('livewire.tasks.partials.task-attachments', [
                    'kind' => 'SUBMISSION',
                    'label' => 'Submission Files',
                    'files' => $openFiles->where('kind', 'SUBMISSION')->values(),
                    'canUpload' => $canAttach,
                    'canDelete' => $canAttach,
                    'emptyLabel' => $canAttach
                        ? 'No files attached yet. Attach photos, scans or documents that evidence the work.'
                        : 'No files were attached to this task.',
                ])
                <div class="space-y-2">
                    <flux:field>
                        <flux:label>What did you do?</flux:label>
                        <flux:textarea
                            rows="3"
                            placeholder="{{ $individual ? 'Only you and the reviewers see this.' : 'The reviewer reads this alongside the task.' }}"
                            wire:model="remarks"
                        />
                    </flux:field>
                </div>
                </section>
            @endif

            {{-- Nothing left for the actor to do. One explicit arm per state
                 that ends their part, and deliberately NO catch-all: a state
                 not listed prints nothing rather than a wrong sentence. --}}
            @if ($nexts === [] && ! $canSubmit)
                <p class="text-xs text-muted-foreground">
                    @if ($myState === 'SUBMITTED')
                        Waiting for management review.
                    @elseif ($myState === 'APPROVED')
                        Approved — waiting for the rest of the team.
                    @elseif ($myState === 'COMPLETED')
                        {{ $individual ? 'Completed' : 'Accepted' }}{{ $open->completed_at ? ' on '.$open->completed_at->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A') : '' }} — nothing further is needed.
                    @elseif ($myState === 'CANCELLED')
                        Management cancelled this task — nothing further is needed.
                    @endif
                </p>
            @endif

            @unless ($canSubmit)
                @include('livewire.tasks.partials.task-attachments', [
                    'kind' => 'SUBMISSION',
                    'label' => 'Submission Files',
                    'files' => $openFiles->where('kind', 'SUBMISSION')->values(),
                    'canUpload' => $canAttach,
                    'canDelete' => $canAttach,
                    'emptyLabel' => $canAttach
                        ? 'No files attached yet. Attach photos, scans or documents that evidence the work.'
                        : 'No files were attached to this task.',
                ])
            @endunless

            {{-- Deliberately not gated on $canAttach: asking about a task that is
                 already under review — or one that was cancelled — is the whole
                 point of having a thread. --}}
            @include('livewire.tasks.partials.task-comments', [
                'comments' => $openComments,
                'side' => $commentSide,
                'canComment' => true,
            ])

            @if ($canSubmit)<div class="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                @if ($canSubmit)
                    <flux:button variant="primary" icon="paper-airplane" wire:click="submitTask">
                        {{ $individual ? 'Submit My Response' : 'Submit for Review' }}
                    </flux:button>
                @endif
            </div>@endif
        </div>
    @endif
</x-work.task-panel>
