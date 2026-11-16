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
<flux:modal name="task-detail" class="w-full max-w-2xl" wire:close="dialogClosed">
    @if ($open)
        <div class="max-h-[80vh] space-y-4 overflow-y-auto">
            <div>
                <flux:heading size="lg">{{ trim($open->code) !== '' ? $open->code : 'Task' }}</flux:heading>
                <flux:text class="mt-1">{{ $hint }}</flux:text>
            </div>

            {{-- The brief is what the work is done against, so it stays above the
                 fold: right under the rework note when there is one, otherwise
                 right under the task summary. --}}
            @if ($returned)
                <div class="space-y-1 rounded-md border border-danger/40 bg-danger/5 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-danger">Returned for Rework</p>
                    <p class="text-sm">
                        {{ trim((string) $reviewNote) !== '' ? $reviewNote : 'No remarks were left with the return.' }}
                    </p>
                </div>
                @include('livewire.tasks.partials.task-attachments', [
                    'kind' => 'BRIEF',
                    'label' => 'Brief from Management',
                    'files' => $openFiles->where('kind', 'BRIEF')->values(),
                    'canUpload' => false,
                    'canDelete' => false,
                    'emptyLabel' => 'No brief was attached to this task.',
                ])
            @endif

            <div class="flex flex-wrap items-center gap-3 rounded-md border border-border p-4">
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ $open->title }}</p>
                    <p class="truncate text-xs text-muted-foreground">
                        @if ($open->project)
                            <span class="num">{{ $open->project->code }}</span> · {{ $open->project->name }}
                        @else
                            —
                        @endif
                    </p>
                </div>
                <div class="ml-auto flex shrink-0 items-center gap-2">
                    <span class="{{ TaskPolicy::priorityPill($open->priority) }}">{{ TaskPolicy::priorityLabel($open->priority) }}</span>
                    <span class="{{ TaskPolicy::statusPill($open->status) }}">{{ TaskPolicy::statusLabel($open->status) }}</span>
                </div>
                {{-- Its own flex item, so on a phone it wraps under the task
                     pills instead of pushing them off the dialog. --}}
                @if ($individual && $myResponse !== null)
                    <span class="{{ $myResponse->pill() }}">Your response: {{ $myResponse->label() }}</span>
                @endif
            </div>

            @unless ($returned)
                @include('livewire.tasks.partials.task-attachments', [
                    'kind' => 'BRIEF',
                    'label' => 'Brief from Management',
                    'files' => $openFiles->where('kind', 'BRIEF')->values(),
                    'canUpload' => false,
                    'canDelete' => false,
                    'emptyLabel' => 'No brief was attached to this task.',
                ])
            @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                {{-- The planned start: a plan from management, not a gate —
                     the task can be started before it. --}}
                <div class="min-w-0 space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Start</p>
                    <div class="break-words text-sm font-medium">
                        @if ($open->start_date !== null)
                            <span class="num">{{ $open->start_date->format('M d, Y') }}</span>
                        @else
                            No start date
                        @endif
                    </div>
                </div>
                <div class="min-w-0 space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Due</p>
                    <div class="break-words text-sm font-medium">
                        @if ($open->due_date !== null)
                            <span class="{{ $openOverdue ? 'num text-danger' : 'num' }}">
                                {{ $open->due_date->format('M d, Y') }}@if ($openOverdue)<span class="text-xs"> · overdue</span>@endif
                            </span>
                        @else
                            No due date
                        @endif
                    </div>
                </div>
                <div class="min-w-0 space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Assigned By</p>
                    <div class="break-words text-sm font-medium">{{ $assignedBy ?? '—' }}</div>
                </div>
                <div class="min-w-0 space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Assigned</p>
                    <div class="break-words text-sm font-medium">
                        <span class="num">{{ $open->assigned_at?->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A') ?? '—' }}</span>
                    </div>
                </div>
            </div>

            {{-- A shared task: the peers on the same pivot. On a GROUP task any
                 of them may start or submit it for everyone, so they are worth
                 naming; on an INDIVIDUAL task each name carries that peer's
                 response state — the state only, never what they handed in. --}}
            @if ($coAssignees !== '')
                <p class="text-xs text-muted-foreground">
                    Working with: <span class="font-medium text-foreground">{{ $coAssignees }}</span>
                </p>
            @endif

            <div class="space-y-1">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Description</p>
                <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">
                    {{ trim((string) $open->description) !== '' ? $open->description : 'No description given.' }}
                </p>
            </div>

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

            <div class="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                <flux:button variant="ghost" wire:click="closeDialog">Close</flux:button>
                @if ($canStart)
                    <flux:button variant="primary" icon="play" wire:click="startTask">
                        {{ $open->status === 'RETURNED' ? 'Resume Task' : 'Start Task' }}
                    </flux:button>
                @endif
                @if ($canSubmit)
                    <flux:button variant="primary" icon="paper-airplane" wire:click="submitTask">
                        {{ $individual ? 'Submit My Response' : 'Submit for Review' }}
                    </flux:button>
                @endif
            </div>
        </div>
    @endif
</flux:modal>
