@php
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
    use App\Modules\WorkManagement\Application\TaskFilePolicy;
@endphp
<x-work.task-panel wire:model.live.self="reviewOpen" :title="$openTask?->title ?? 'Task'" :code="$openTask?->code">
    @if ($openTask !== null)
        @php
            $actions = TaskPolicy::managerCanMoveTo($openTask->status, $openTask->submission_mode);
            if (! $openTaskMayReview) {
                $actions = $canManage ? array_values(array_intersect($actions, ['CANCELLED'])) : [];
            }
            $individual = $openTask->isIndividual();
            $closed = TaskPolicy::isClosed($openTask->status);
            $selfReview = $runsProject && $openTaskIsAssignee && ! $closed;
            $leaderOnly = $runsProject && ! $canEditWork;
            $canEdit = ! $closed && ($canEditWork || ($leaderOnly && $openTaskRunnable));
            $canChangeBrief = $canEditWork && in_array($openTask->status, TaskFilePolicy::BRIEF_EDITABLE_STATUSES, true);
            $needsRemark = in_array('RETURNED', $actions, true) || in_array('RETURNED', $openTaskManualMoves, true);
            $taskOverdue = TaskPolicy::isOverdue(ProjectsData::taskShape($openTask), $today);
        @endphp
        <x-slot:meta><a href="{{ route('projects.show', $project->id) }}" class="max-w-56 truncate hover:text-foreground">{{ $project->name }}</a></x-slot:meta>
        <x-slot:headingActions>
            <div class="flex items-center gap-1">
                @if (in_array('COMPLETED', $actions, true))<flux:button size="sm" variant="primary" icon="check" wire:click="review('COMPLETED')" :disabled="$individual && ($openTaskGaps !== [] || $openTaskWaitingOn !== '')" :aria-describedby="$individual ? 'complete-reason' : null">{{ $individual ? 'Complete task' : 'Accept task' }}</flux:button>@endif
                @if (in_array('CANCELLED', $actions, true))<flux:dropdown><flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Task actions" /><flux:menu><flux:menu.item variant="danger" wire:click="review('CANCELLED')">Cancel task</flux:menu.item></flux:menu></flux:dropdown>@endif
            </div>
        </x-slot:headingActions>
        @if ($canEditWork && ! $closed && ! $openTaskModeLocked)
            <x-slot:heading>
                @if ($editingContent)<flux:input aria-label="Task title" wire:model="contentTitle" wire:loading.attr="disabled" wire:target="saveContent" form="task-content-form" maxlength="{{ TaskPolicy::TITLE_LIMIT }}" required class="!text-xl !font-semibold" />
                @else<flux:heading level="2" size="lg"><button type="button" class="w-full break-words text-left !text-xl !font-semibold !leading-snug !tracking-tight hover:text-primary" wire:click="editContent" aria-label="Edit title: {{ $openTask->title }}" title="Edit task title and description">{{ $openTask->title }}</button></flux:heading>
                @endif
            </x-slot:heading>
        @endif
        <div class="space-y-6" wire:key="task-panel-{{ $openTask->id }}">
            @if ($openTask->isSubtask())
                @if ($openTask->parent !== null)
                    <button type="button" wire:click="openTask('{{ $openTask->parent->id }}')" class="block w-full rounded text-left focus-visible:outline-2 focus-visible:outline-primary"><x-work.task-parent :task="$openTask" /></button>
                @else
                    <x-work.task-parent :task="$openTask" />
                @endif
            @endif
            @if ($selfReview)<p class="text-xs text-muted-foreground">You're one of this task's assignees, so another reviewer has to decide it.</p>
            @endif
            @include('livewire.projects.partials.task-properties')
            <flux:error name="taskProperty" />
            @error('taskProperty')<flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="resetDetails">Reload latest properties</flux:button>@enderror

            <section class="space-y-2" aria-label="Task brief">
                @if ($editingContent)
                    <form id="task-content-form" wire:submit="saveContent" class="space-y-3">
                        <flux:textarea label="Description" wire:model="contentDescription" rows="4" maxlength="{{ TaskPolicy::DESCRIPTION_LIMIT }}" />
                        <div class="flex justify-end gap-2"><flux:button size="sm" variant="ghost" type="button" wire:click="$set('editingContent', false)">Cancel</flux:button><flux:button size="sm" type="submit" variant="primary">Save brief</flux:button></div>
                    </form>
                @else
                    <h3 class="ppm-section-title">Description</h3>
                    @if ($canEditWork && ! $closed && ! $openTaskModeLocked)
                        <button type="button" class="block w-full whitespace-pre-line break-words rounded text-left text-sm text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-primary" wire:click="editContent" aria-label="Edit description">{{ trim($openTask->description ?? '') !== '' ? $openTask->description : 'Add a description…' }}</button>
                    @else<p class="whitespace-pre-line break-words text-sm text-muted-foreground">{{ trim($openTask->description ?? '') !== '' ? $openTask->description : 'No description given.' }}</p>
                    @endif
                @endif
            </section>

            @if (! $openTask->isSubtask() && ($openTask->has_subtasks || $canAddSubtask))
                <section class="space-y-3" aria-label="Task subtasks">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="ppm-section-title">Subtasks</h3>
                        @if ($canAddSubtask)<flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="openSubtaskCreation('{{ $openTask->id }}')">Add subtask</flux:button>
                        @endif
                    </div>
                    @if ($openTask->has_subtasks)<x-work.subtask-progress :task="$openTask" /><x-work.subtask-list :rows="$openSubtasks" :today="$today" />
                    @endif
                    @if ($openSubtasks !== null && $openSubtasks->total() > 0)<flux:button size="sm" variant="ghost" icon="list-bullet" wire:click="showSubtasks('{{ $openTask->id }}')">View subtasks in List</flux:button>
                    @endif
                </section>
            @endif

            <section class="space-y-3" aria-label="Task references">
                <div class="flex items-center justify-between gap-3"><h3 class="ppm-section-title">Brief &amp; references</h3>
                    @if ($canChangeBrief)
                        <div x-data><flux:button type="button" size="sm" variant="ghost" icon="paper-clip" x-on:click="$refs.briefInput.click()">Attach</flux:button><input type="file" class="hidden" x-ref="briefInput" wire:model="reviewBriefUpload" aria-label="Attach task reference" /></div>
                    @endif
                </div>
                @foreach ($openTaskBrief as $f)
                    <x-work.file :file="$f" :href="route('projects.task-files.download', $f->id)" wire:key="brief-{{ $f->id }}">
                        @if ($canChangeBrief)<x-slot:actions><flux:button size="sm" square variant="ghost" class="text-danger!" icon="trash" wire:click="deleteFile('{{ $f->id }}')" aria-label="Remove {{ $f->file_name }}" /></x-slot:actions>
@endif
                    </x-work.file>
                @endforeach
                @if ($openTaskBrief->isEmpty())<p class="text-xs text-muted-foreground">No files attached.</p>
@endif
            </section>

            @if ($individual)
                @include('livewire.projects.partials.task-responses')
            @elseif ($openTask->submitted_at !== null || trim($openTask->remarks ?? '') !== '' || $openTaskSubmitted->isNotEmpty())
                <section class="space-y-3 rounded-xl border border-border p-4" aria-label="Task submission">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="ppm-section-title">Team response</h3><span class="num text-xs text-muted-foreground">{{ $openTask->submitted_at ? 'Submitted '.ProjectsData::fmtDateTime($openTask->submitted_at) : 'Last submission' }}</span></div>
                    @if ($openTask->submitter !== null)<p class="text-xs text-muted-foreground">{{ ProjectsData::employeeName($openTask->submitter) }}</p>
@endif
                    @if (trim($openTask->remarks ?? '') !== '')<p class="whitespace-pre-line break-words text-sm">{{ $openTask->remarks }}</p>
@endif
                    @foreach ($openTaskSubmitted as $f)<x-work.file :file="$f" :href="route('projects.task-files.download', $f->id)" wire:key="submission-{{ $f->id }}" />
@endforeach
                </section>
            @endif

            @if ($openTask->review_remarks)
                <section class="space-y-2 rounded-xl bg-muted/40 p-4"><h3 class="ppm-section-title">Review remarks{{ $openTask->reviewed_by !== null ? ' · '.$accountName($openTask->reviewed_by) : '' }}</h3><p class="whitespace-pre-line break-words text-sm">{{ $openTask->review_remarks }}</p></section>
            @endif

            <details class="text-xs text-muted-foreground"><summary class="w-fit cursor-pointer">Assignment</summary><p class="mt-2">Assigned by {{ $accountName($openTask->assigned_by) }} · <span class="num">{{ ProjectsData::fmtDateTime($openTask->assigned_at) }}</span></p></details>

            <section class="space-y-3 border-t border-border pt-5" aria-label="Task discussion">
                <div class="flex items-center gap-2"><h3 class="ppm-section-title">Discussion</h3>
@if ($openTaskComments->isNotEmpty())<span class="ppm-count">{{ $openTaskComments->count() }}</span>
@endif</div>
                @foreach ($openTaskComments as $comment)<x-work.comment :comment="$comment" wire:key="comment-{{ $comment->id }}" />
@endforeach
                @if ($openTaskCanComment)
                <form wire:submit="postComment" class="space-y-2"><flux:textarea aria-label="Add a comment" placeholder="Write a comment…" rows="2" wire:model="commentBody" /><div class="flex justify-end"><flux:button type="submit" size="sm" variant="outline" icon="paper-airplane">Post comment</flux:button></div></form>
                @endif
            </section>

        </div>

        @if ($openTask->awaitsReview() && $openTaskMayReview || $individual && in_array('COMPLETED', $actions, true))
            <x-slot:footer>
                <div class="space-y-3">
                    @if ($openTaskMayReview && ! $openTask->isSubtask() && (int) $openTask->subtasks_count - (int) $openTask->subtasks_completed - (int) $openTask->subtasks_cancelled > 0)
                        <p class="flex items-center gap-1.5 text-xs text-warning"><flux:icon.exclamation-triangle class="size-3.5" />{{ $openTask->subtasks_count - $openTask->subtasks_completed - $openTask->subtasks_cancelled }} subtasks still open</p>
                    @endif
                    @if ($openTask->awaitsReview() || $manualStatus === 'RETURNED')
                        <flux:textarea label="{{ count($actions) > 0 ? 'Review remark' : 'Status note' }}{{ $needsRemark ? ' (required to return)' : '' }}" aria-label="Review remark" rows="2" placeholder="Add a note for the team…" wire:model="remarks" />
                    @else
                        <details><summary class="w-fit cursor-pointer text-xs text-muted-foreground">Add a status note</summary><flux:textarea class="mt-2" aria-label="Status note" rows="2" wire:model="remarks" /></details>
                    @endif
                    @if (count($actions) > 0)
                        <div class="flex flex-wrap justify-end gap-2">
                            @if (in_array('RETURNED', $actions, true))<flux:button size="sm" variant="outline" icon="arrow-uturn-left" wire:click="review('RETURNED')">Return for rework</flux:button>
@endif
                        </div>
                        @if (in_array('COMPLETED', $actions, true) && $individual)<p id="complete-reason" class="text-right text-xs text-muted-foreground">{{ $openTaskWaitingOn !== '' ? $openTaskWaitingOn : 'Every response is approved.' }}</p>
@endif
                    @endif
                </div>
            </x-slot:footer>
        @endif
    @endif
</x-work.task-panel>
