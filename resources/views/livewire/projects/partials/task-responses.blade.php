{{--
    The responses to an INDIVIDUAL task, one per current assignee — the review
    dialog's stand-in for the single "Submitted files" / "Last submission"
    block a GROUP task has. Included by dialog-task-review.blade.php and shares
    Show's view variables: $openTaskAssignees, $openTaskResponses (keyed by
    employee id, WorkRecords::responses()), $openTaskSubmitted,
    $openTaskFormerFiles, $openTaskMayReview, $expandedMemberId and
    $accountName.

    THE PRIVACY RULE (TaskResponseView, and "INDIVIDUAL RESPONSES ARE
    PRIVATE" in the WorkRecords class docblock): a response's note, files and
    review are that member's and the reviewer's alone. Every row shows who is
    on the task and their STATE; only a row whose `content_visible` is true
    can open, and the panel reads content keys only then — for anyone else
    responses() does not even carry them, and files() never hands over a
    peer's evidence.

    Approve / Return come from the reviewer map (ProjectsData::
    reviewerMovesFrom()) and only for a reviewer off the crew
    ($openTaskMayReview — a Projects manager or the project's Task Leader). They post the row id and revision drawn here;
    ReviewTaskResponse refuses a response that changed since.

    Stacked rows, not a table: at 390px a member, their state and the panel
    under them still read top to bottom.
--}}
@php
    use App\Livewire\Projects\ProjectsData;
    use App\Support\EmployeeIdentity;
@endphp
<div class="space-y-2">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Responses</p>
        <p class="num text-xs text-muted-foreground">{{ $openTask->responseSummary() }}</p>
    </div>

    @if (count($openTaskAssignees) === 0)
        <p class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
            Nobody is assigned to this task, so there are no responses to review.
        </p>
    @else
        <ul class="divide-y divide-border overflow-hidden rounded-md border border-border">
            @foreach ($openTaskAssignees as $member)
                @php
                    $memberId = (string) $member->getKey();
                    $response = $openTaskResponses->get($memberId);
                @endphp
                @continue($response === null)
                @php
                    $expanded = $response->content_visible && $expandedMemberId === $memberId;
                    $moves = $openTaskMayReview ? ProjectsData::reviewerMovesFrom($openTask->status, $response->status) : [];
                    $meta = EmployeeIdentity::meta(EmployeeIdentity::besideName($member), $member->position);
                @endphp
                <li wire:key="response-{{ $memberId }}">
                    @if ($response->content_visible)
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-accent focus-visible:bg-accent"
                            wire:click="selectResponse('{{ $memberId }}')"
                            aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                        >
                    @else
                        <div class="flex w-full items-center gap-3 px-3 py-2">
                    @endif
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted">
                                <span class="num text-xs font-semibold">{{ ProjectsData::initials($member) }}</span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block break-words text-sm font-medium">{{ ProjectsData::employeeName($member) }}</span>
                                <span class="num block break-words text-xs text-muted-foreground">
                                    {{ $meta !== '' ? $meta : '—' }}
                                    @if ($response->content_visible && $response->submitted_at !== null)
                                        · submitted {{ ProjectsData::fmtDateTime($response->submitted_at) }}
                                    @endif
                                </span>
                            </span>
                            <span class="{{ $response->pill() }} shrink-0">{{ $response->label() }}</span>
                    @if ($response->content_visible)
                        </button>
                    @else
                        </div>
                    @endif

                    @if ($expanded)
                        @php
                            $memberFiles = $openTaskSubmitted->where('employee_id', $memberId)->values();
                            // Printed inline below: the paragraphs keep the
                            // writer's line breaks (whitespace-pre-wrap).
                            $note = match (true) {
                                $response->id === null => 'Nothing handed in yet.',
                                trim((string) $response->remarks) !== '' => trim((string) $response->remarks),
                                default => 'No note with this response.',
                            };
                            $reviewNote = trim((string) $response->review_remarks);
                        @endphp
                        <div class="space-y-3 border-t border-border bg-muted/20 px-3 py-3">
                            <div class="space-y-1">
                                <p class="text-xs uppercase tracking-wide text-muted-foreground">
                                    {{ $response->is_self ? 'Your Note' : 'Their Note' }}
                                </p>
                                <p class="whitespace-pre-wrap break-words rounded-md border border-border bg-card p-3 text-sm">{{ $note }}</p>
                            </div>

                            <div class="space-y-1">
                                <p class="text-xs uppercase tracking-wide text-muted-foreground">Files</p>
                                @if ($memberFiles->isEmpty())
                                    <p class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                                        No files attached.
                                    </p>
                                @else
                                    <ul class="space-y-1">
                                        @foreach ($memberFiles as $f)
                                            <li wire:key="response-file-{{ $f->id }}" class="flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2">
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

                            @if ($response->reviewed_by !== null || $reviewNote !== '')
                                <div class="space-y-1">
                                    <p class="text-xs uppercase tracking-wide text-muted-foreground">
                                        Last review · {{ $accountName($response->reviewed_by) }}
                                        @if ($response->reviewed_at !== null)
                                            · <span class="num">{{ ProjectsData::fmtDateTime($response->reviewed_at) }}</span>
                                        @endif
                                    </p>
                                    <p class="whitespace-pre-wrap break-words rounded-md border border-border bg-card p-3 text-sm">{{ $reviewNote !== '' ? $reviewNote : 'No note with that review.' }}</p>
                                </div>
                            @endif

                            @if ($moves !== [] && $response->id !== null)
                                <div class="space-y-2">
                                    <flux:label for="response-remark">
                                        Note to {{ $member->first_name }}{{ in_array('RETURNED', $moves, true) ? ' (required to return)' : '' }}
                                    </flux:label>
                                    <flux:textarea
                                        id="response-remark"
                                        rows="2"
                                        placeholder="What {{ $member->first_name }} should know about this decision"
                                        wire:model="responseRemark"
                                    />
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @if (in_array('RETURNED', $moves, true))
                                            <flux:button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                wire:click="reviewResponse('RETURNED', '{{ $response->id }}', {{ (int) $response->revision }})"
                                                wire:loading.attr="disabled"
                                            >
                                                <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'undo-2', 'class' => 'size-4'])</x-slot:icon>
                                                Return for Rework
                                            </flux:button>
                                        @endif
                                        @if (in_array('APPROVED', $moves, true))
                                            <flux:button
                                                type="button"
                                                size="sm"
                                                variant="primary"
                                                wire:click="reviewResponse('APPROVED', '{{ $response->id }}', {{ (int) $response->revision }})"
                                                wire:loading.attr="disabled"
                                            >
                                                <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'check', 'class' => 'size-4'])</x-slot:icon>
                                                Approve Response
                                            </flux:button>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Evidence from people since taken off the task: kept for the record,
         never a response of anyone's now. Only a reviewer is handed it. --}}
    @if ($openTaskFormerFiles->isNotEmpty())
        <div class="space-y-1 pt-1">
            <p class="text-xs uppercase tracking-wide text-muted-foreground">Files from people no longer on this task</p>
            <ul class="space-y-1">
                @foreach ($openTaskFormerFiles as $f)
                    <li wire:key="former-file-{{ $f->id }}" class="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2">
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
        </div>
    @endif
</div>
