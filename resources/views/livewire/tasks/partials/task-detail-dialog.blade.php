{{--
    The task detail dialog — port of my-tasks/task-detail-dialog.tsx.

    Rendered from the My Tasks page component; everything here reads the
    variables its render() passed. The modal element always exists so
    Flux::modal('task-detail')->show() has something to open; the body renders
    only while a task is open.

    Divergence noted: the source's Radix dialog scrolled its own content
    (max-h-[90vh] overflow-y-auto on DialogContent); here that sits on a
    wrapper div inside the Flux modal, which has no free-tier equivalent prop.
--}}
@php
    use App\Domain\Projects\TaskPolicy;
    use App\Support\ManilaClock;

    $hint = $open ? match ($open->status) {
        'PENDING' => 'Start the task when you begin working on it.',
        'IN_PROGRESS' => 'Submit it for review once the work is done.',
        'RETURNED' => 'Management sent this back — pick it up again and resubmit.',
        'SUBMITTED' => 'Submitted and waiting for management review.',
        'CANCELLED' => 'This task was called off.',
        default => 'This task is closed.',
    } : '';

    $returned = $open?->status === 'RETURNED';
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
                    <p class="text-xs font-semibold uppercase tracking-wide text-danger">Returned for rework</p>
                    <p class="text-sm">
                        {{ trim((string) $open->review_remarks) !== '' ? $open->review_remarks : 'No remarks were left with the return.' }}
                    </p>
                </div>
                @include('livewire.tasks.partials.task-attachments', [
                    'kind' => 'BRIEF',
                    'label' => 'Brief from management',
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
            </div>

            @unless ($returned)
                @include('livewire.tasks.partials.task-attachments', [
                    'kind' => 'BRIEF',
                    'label' => 'Brief from management',
                    'files' => $openFiles->where('kind', 'BRIEF')->values(),
                    'canUpload' => false,
                    'canDelete' => false,
                    'emptyLabel' => 'No brief was attached to this task.',
                ])
            @endunless

            <div class="grid gap-4 sm:grid-cols-3">
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
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Assigned by</p>
                    <div class="break-words text-sm font-medium">{{ $assignedBy ?? '—' }}</div>
                </div>
                <div class="min-w-0 space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Assigned</p>
                    <div class="break-words text-sm font-medium">
                        <span class="num">{{ $open->assigned_at?->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A') ?? '—' }}</span>
                    </div>
                </div>
            </div>

            <div class="space-y-1">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Description</p>
                <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">
                    {{ trim((string) $open->description) !== '' ? $open->description : 'No description given.' }}
                </p>
            </div>

            @if (trim((string) $open->remarks) !== '')
                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Your last submission</p>
                    <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">{{ $open->remarks }}</p>
                </div>
            @endif

            {{-- The rework note has to survive "Resume task" — it is what the
                 employee is working against until they resubmit. --}}
            @if (! $returned && trim((string) $open->review_remarks) !== '')
                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Review remarks</p>
                    <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">{{ $open->review_remarks }}</p>
                </div>
            @endif

            {{-- Attaching the evidence and describing it are one action, so the
                 files sit directly on top of the note the reviewer reads. --}}
            @if ($canSubmit)
                @include('livewire.tasks.partials.task-attachments', [
                    'kind' => 'SUBMISSION',
                    'label' => 'Your files',
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
                            placeholder="The reviewer reads this alongside the task."
                            wire:model="remarks"
                        />
                    </flux:field>
                </div>
            @endif

            @if ($nexts === [])
                <p class="text-xs text-muted-foreground">
                    @if ($open->status === 'SUBMITTED')
                        Waiting for management review.
                    @elseif ($open->status === 'COMPLETED')
                        Accepted{{ $open->completed_at ? ' on '.$open->completed_at->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A') : '' }} — nothing further is needed.
                    @else
                        Management cancelled this task — nothing further is needed.
                    @endif
                </p>
            @endif

            @unless ($canSubmit)
                @include('livewire.tasks.partials.task-attachments', [
                    'kind' => 'SUBMISSION',
                    'label' => 'Your files',
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
                        {{ $open->status === 'RETURNED' ? 'Resume task' : 'Start task' }}
                    </flux:button>
                @endif
                @if ($canSubmit)
                    <flux:button variant="primary" icon="paper-airplane" wire:click="submitTask">
                        Submit for review
                    </flux:button>
                @endif
            </div>
        </div>
    @endif
</flux:modal>
