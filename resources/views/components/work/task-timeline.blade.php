@props(['rows', 'timeline', 'today', 'own' => false, 'editable' => [], 'canChangeDue' => false, 'grouped' => false, 'sortKey' => 'stack'])
@php
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
    use App\Modules\WorkManagement\Application\TaskTimeline;
    use App\Modules\WorkManagement\Domain\Tasks\TaskInbox;
    $calendar = $timeline->calendar();
    $hasEditableDates = ! $own && $rows->contains(fn ($task) => ($editable[$task->id] ?? false) && ! TaskPolicy::isClosed($task->status));
    $listedParents = ! $own && $sortKey === 'stack' ? array_fill_keys($rows->whereNull('parent_id')->pluck('id')->all(), true) : [];
@endphp
<section class="ppm-surface overflow-hidden" aria-label="Task timeline" wire:key="{{ $own ? 'own' : 'project' }}-timeline" x-data="taskTimeline($wire)" x-effect="checkWindow($wire.timelineFrom, $wire.timelineScale)" x-on:work-timeline-focus-today.window="focusToday()" x-bind:data-date-editing="editing" data-scales="{{ json_encode(array_keys(TaskTimeline::SCALES)) }}" data-from="{{ $timeline->from }}" data-days="{{ $timeline->days }}" data-today="{{ $timeline->todayOffset() }}" data-scale="{{ $timeline->days }}" style="--ppm-days: {{ $timeline->days }}" :aria-busy="busy || zooming">
    <header class="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3">
        <div class="flex items-center gap-1"><flux:button type="button" size="sm" square variant="ghost" icon="chevron-left" wire:click="shiftTimeline(-1)" aria-label="Previous date range" /><flux:button size="sm" variant="outline" wire:click="todayOnTimeline">Today</flux:button><flux:button type="button" size="sm" square variant="ghost" icon="chevron-right" wire:click="shiftTimeline(1)" aria-label="Next date range" /></div>
        <span class="num text-sm font-medium">{{ $timeline->label() }}</span>
        <x-kit.select size="sm" aria-label="Timeline scale" class="ml-auto w-36" wire:model.live="timelineScale">
            @foreach (TaskTimeline::SCALES as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </x-kit.select>
    </header>
    <div class="flex flex-wrap items-center justify-end gap-2 border-b border-border px-4 py-2">
        @if ($hasEditableDates)
            <flux:button type="button" size="sm" variant="ghost" icon="pencil-square" class="ppm-gantt-edit-mode" x-on:click="cancel(); editing = !editing" x-bind:aria-pressed="editing"><span x-text="editing ? 'Finish editing dates' : 'Edit dates'">Edit dates</span></flux:button>
        @endif
        <div class="flex items-center gap-1" role="group" aria-label="Timeline zoom">
            <flux:button type="button" size="sm" square variant="ghost" icon="minus" aria-label="Zoom out timeline" x-on:click="changeZoom(-1)" x-bind:disabled="zooming || scaleIndex >= scales.length - 1" />
            <button type="button" class="num min-w-14 rounded px-2 py-1 text-xs focus-visible:outline-2 focus-visible:outline-primary" x-on:click="resetZoom()" aria-label="Reset timeline zoom" x-bind:disabled="zooming || busy || scaleIndex === 2">Reset</button>
            <flux:button type="button" size="sm" square variant="ghost" icon="plus" aria-label="Zoom in timeline" x-on:click="changeZoom(1)" x-bind:disabled="zooming || scaleIndex <= 0" />
        </div>
    </div>
    <p class="sr-only" id="timeline-keyboard-help">Enable Edit dates before dragging a bar or changing dates with arrow keys. Arrow keys on an editable bar or date handle move one day. Hold Shift for one week. Enter opens task details or the date editor.</p>
    <div class="ppm-gantt-scroll" x-ref="scroller" data-work-pan tabindex="0" role="region" aria-label="Scrollable task calendar">
        <table class="hris-table ppm-gantt-table">
            <thead><tr><th class="ppm-gantt-label">Task</th><th class="ppm-gantt-axis" aria-label="Schedule">
                <div class="ppm-gantt-weeks" aria-hidden="true">
                    @foreach ($timeline->periods() as $period)<span style="grid-column: span {{ $period['span'] }}" title="{{ $period['label'] }}">{{ $period['label'] }}</span>
                    @endforeach
                </div>
                @if ($timeline->days <= 84)
                <div class="ppm-gantt-days" aria-hidden="true">
                    @foreach ($calendar as $day)<span class="{{ $day['today'] ? 'ppm-gantt-today' : ($day['weekend'] ? 'ppm-gantt-weekend' : '') }}" title="{{ $day['label'] }}">{{ $day['day'] }}</span>
                    @endforeach
                </div>
                @endif
            </th></tr></thead>
            <tbody>
                @if ($rows->isEmpty())
                    <tr><td colspan="2" class="!p-8 text-sm text-muted-foreground">No tasks match this view.</td></tr>
                @endif
                @php
                    $previousSection = null;
                @endphp
                @foreach ($rows as $task)
                    @php
                        $start = $task->start_date?->format('Y-m-d');
                        $due = $task->due_date?->format('Y-m-d');
                        $position = $timeline->position($start, $due);
                        $mayStart = ! $own && ($editable[$task->id] ?? false) && ! TaskPolicy::isClosed($task->status);
                        $mayDue = $mayStart && $canChangeDue;
                        $mayMove = $mayStart && ($mayDue || $due === null);
                        $section = $own && $sortKey === 'focus' ? $task->inboxBucket($today) : ($grouped ? ($task->effective_group_id ?? 'NONE') : null);
                        $sectionLabel = $own ? (TaskInbox::BUCKETS[$section] ?? '') : ($task->group_name ?? 'No activity');
                        $dateLabel = $start && $due ? ProjectsData::fmtDate($task->start_date).' – '.ProjectsData::fmtDate($task->due_date) : ($start ? 'Starts '.ProjectsData::fmtDate($task->start_date) : ($due ? 'Due '.ProjectsData::fmtDate($task->due_date) : 'No dates'));
                    @endphp
                    @if ($section !== null && $section !== $previousSection)
                        <tr wire:key="timeline-section-{{ $section }}"><td colspan="2" class="ppm-gantt-section"><span>{{ $sectionLabel }}</span></td></tr>
                    @endif
                    @php
                        $previousSection = $section;
                    @endphp
                    <tr wire:key="timeline-row-{{ $task->id }}" data-gantt-row="{{ $task->id }}">
                        <td class="ppm-gantt-label">
                            <div class="flex items-start gap-1">
                                <button type="button" class="min-w-0 flex-1 rounded px-1 py-2 text-left focus-visible:outline-2 focus-visible:outline-primary {{ $task->isSubtask() ? 'ml-3 border-l border-border pl-3' : '' }}" wire:click="openTask('{{ $task->id }}')" aria-label="Open {{ $task->code }}: {{ $task->title }}">
                                    @unless (isset($listedParents[$task->parent_id ?? '']))<x-work.task-parent :task="$task" class="mb-1" />
                                    @endunless
                                    <span class="block truncate text-sm font-medium" title="{{ $task->title }}">{{ $task->title }}</span>
                                    <span class="num mt-1 block text-xs text-muted-foreground">{{ $task->code }} @if ($own)· {{ $task->project?->name }}
                                        @endif</span>
                                    @if ($own)<x-work.project-context :project="$task->project" class="mt-1 max-w-full" />
                                    @else<x-work.subtask-progress :task="$task" class="mt-1" />
                                    @endif
                                </button>
                                @if ($mayStart)<flux:button type="button" size="sm" square variant="ghost" icon="calendar-days" class="mt-1 shrink-0" wire:click="openSchedule('{{ $task->id }}')" aria-label="Schedule {{ $task->title }}" />
                                @endif
                            </div>
                        </td>
                        <td class="ppm-gantt-track" data-track>
                            <button type="button" class="ppm-gantt-cell-open" data-pan-open tabindex="-1" wire:click="openTask('{{ $task->id }}')" aria-label="Open task details for {{ $task->title }}"></button>
                            @foreach ($calendar as $index => $day)
                                @if ($day['weekend'])<span class="ppm-gantt-shade" aria-hidden="true" style="left: calc({{ $index }} * var(--ppm-day-size))"></span>
                                @endif
                            @endforeach
                            @if ($timeline->todayOffset() >= 0 && $timeline->todayOffset() < $timeline->days)<span class="ppm-gantt-now" aria-hidden="true" style="left: calc({{ $timeline->todayOffset() }} * var(--ppm-day-size))"></span>
                            @endif
                            @if (in_array($position['kind'], ['range', 'start', 'due'], true))
                                <div data-gantt-bar="{{ $task->id }}" data-start="{{ $start }}" data-due="{{ $due }}" data-edit-start="{{ $mayStart ? '1' : '0' }}" data-edit-due="{{ $mayDue ? '1' : '0' }}" class="ppm-gantt-bar {{ $own ? $task->myStatusPill() : TaskPolicy::statusPill($task->status) }} {{ $position['kind'] !== 'range' ? 'ppm-gantt-point' : '' }}" style="left: calc({{ $position['offset'] }} * var(--ppm-day-size)); width: calc({{ $position['span'] }} * var(--ppm-day-size))" title="{{ $dateLabel }} · {{ $own ? $task->myStatusLabel() : TaskPolicy::statusLabel($task->status) }}">
                                    <button type="button" class="ppm-gantt-bar-body" x-bind:data-pan-open="editing ? null : ''" data-movable="{{ $mayMove ? '1' : '0' }}" x-on:click="openTask($event, '{{ $task->id }}')" x-on:pointerdown="begin($event, $el.parentElement, 'move')" @if ($mayMove) x-on:keydown.arrow-left="if (editing) { $event.preventDefault(); nudge($event, $el.parentElement, 'move', -1); }" x-on:keydown.arrow-right="if (editing) { $event.preventDefault(); nudge($event, $el.parentElement, 'move', 1); }" aria-describedby="timeline-keyboard-help" @endif aria-label="{{ $task->title }}: {{ $dateLabel }}, {{ $own ? $task->myStatusLabel() : TaskPolicy::statusLabel($task->status) }}">
                                        @if ($position['before'])<span aria-hidden="true">‹</span>
                                        @endif
                                        <span class="truncate">{{ $position['kind'] === 'range' ? $task->title : ($position['kind'] === 'due' ? 'Due' : 'Start') }}</span>
                                        @if ($position['after'])<span aria-hidden="true">›</span>
                                        @endif
                                    </button>
                                    @if ($mayStart && $start !== null && ! $position['before'])
                                        <button type="button" class="ppm-gantt-handle ppm-gantt-handle-start" x-on:pointerdown.stop="begin($event, $el.parentElement, 'start')" x-on:click.stop="openEditor($event, '{{ $task->id }}')" x-on:keydown.arrow-left.prevent.stop="nudge($event, $el.parentElement, 'start', -1)" x-on:keydown.arrow-right.prevent.stop="nudge($event, $el.parentElement, 'start', 1)" aria-label="Change start date for {{ $task->title }}" aria-describedby="timeline-keyboard-help"></button>
                                    @endif
                                    @if ($mayDue && $due !== null && ! $position['after'])
                                        <button type="button" class="ppm-gantt-handle ppm-gantt-handle-due" x-on:pointerdown.stop="begin($event, $el.parentElement, 'due')" x-on:click.stop="openEditor($event, '{{ $task->id }}')" x-on:keydown.arrow-left.prevent.stop="nudge($event, $el.parentElement, 'due', -1)" x-on:keydown.arrow-right.prevent.stop="nudge($event, $el.parentElement, 'due', 1)" aria-label="Change due date for {{ $task->title }}" aria-describedby="timeline-keyboard-help"></button>
                                    @endif
                                </div>
                            @elseif (in_array($position['kind'], ['before', 'after'], true))
                                <button type="button" class="ppm-gantt-outside" wire:click="showOnTimeline('{{ $task->id }}')" aria-label="Show {{ $task->title }} in date range"><flux:icon.arrow-right-circle class="size-4" /><span>{{ $dateLabel }}</span></button>
                            @else
                                <button type="button" class="ppm-gantt-outside" wire:click="{{ $mayStart ? 'openSchedule' : 'openTask' }}('{{ $task->id }}')">{{ $position['kind'] === 'invalid' ? 'Check dates' : ($mayStart ? 'Set dates' : 'Unscheduled') }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div x-cloak x-show="previewLabel" x-text="previewLabel" class="border-t border-border px-4 py-2 text-xs text-muted-foreground"></div>
    <x-kit.pagination :paginator="$rows" noun="tasks" />
</section>
