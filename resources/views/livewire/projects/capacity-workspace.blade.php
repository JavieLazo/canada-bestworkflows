@php
    use App\Modules\WorkManagement\Domain\Projects\CapacityWindow;
    use App\Modules\WorkManagement\Domain\Projects\PlanningHours;
@endphp
<section class="space-y-5" aria-label="Availability planning">
    @if (! $permissions['view'])
        <div class="ppm-surface p-10 text-center">You no longer have access to this availability view.</div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="ppm-section-title">{{ $own ? 'Your availability' : 'Team availability' }}</h2><p class="mt-1 text-xs text-muted-foreground">{{ \Carbon\CarbonImmutable::parse($weeks[0])->format(\Carbon\CarbonImmutable::parse($weeks[0])->year === \Carbon\CarbonImmutable::parse($weeks[3])->addDays(6)->year ? 'M j' : 'M j, Y') }}–{{ \Carbon\CarbonImmutable::parse($weeks[3])->addDays(6)->format('M j, Y') }} · Planned / available hours</p></div>
            <div class="ppm-actions">
                @if ($issues->total() > 0)<flux:button size="sm" variant="outline" icon="exclamation-triangle" wire:click="$set('issuesOpen', true)">Review estimates <span class="ppm-count">{{ $issues->total() }}</span></flux:button>
                @endif
                <div class="flex items-center gap-2"><flux:button size="sm" square variant="ghost" icon="chevron-left" wire:click="moveWindow(-1)" :disabled="CapacityWindow::move($from, -1) === $from" aria-label="Previous four weeks" /><flux:button size="sm" variant="outline" wire:click="moveWindow(0)">This week</flux:button><flux:button size="sm" square variant="ghost" icon="chevron-right" wire:click="moveWindow(1)" :disabled="CapacityWindow::move($from, 1) === $from" aria-label="Next four weeks" />
            </div></div>
        </div>
        @if (! $own)<div class="ppm-collection-toolbar"><flux:input size="sm" wire:model.live.debounce.250ms="peopleSearch" icon="magnifying-glass" placeholder="Find a person…" aria-label="Search availability people" class="ppm-search" /></div>
        @endif
        @if ($own)
            @foreach ($people as $person)<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ($weeks as $week)<div class="space-y-2" wire:key="own-capacity-{{ $week }}"><h3 class="text-xs font-medium text-muted-foreground">{{ \Carbon\CarbonImmutable::parse($week)->format('M j') }}–{{ \Carbon\CarbonImmutable::parse($week)->addDays(6)->format('M j') }}</h3><x-work.capacity-cell :person="$person" :week="$week" :cell="$person->weeks[$week]" :mine="true" /></div>
                @endforeach
            </div>
            @endforeach
        @else
            <div class="ppm-surface max-h-[65dvh] overflow-auto" data-work-pan tabindex="0" role="region" aria-label="Weekly team workload"><table class="hris-table w-full min-w-[48rem] table-fixed">
                <thead class="sticky top-0 z-20"><tr><th class="sticky left-0 z-30 w-44 border-r border-border bg-card">Person</th>@foreach ($weeks as $week)<th>{{ \Carbon\CarbonImmutable::parse($week)->format('M j') }}–{{ \Carbon\CarbonImmutable::parse($week)->addDays(6)->format('M j') }}</th>
                @endforeach</tr></thead><tbody>
                    @forelse ($people as $person)<tr wire:key="capacity-person-{{ $person->employee_id }}"><td class="sticky left-0 z-10 border-r border-border bg-card"><span class="block max-w-48 truncate font-medium" title="{{ $person->name }}">{{ $person->name }}</span></td>
                        @foreach ($weeks as $week)<td><x-work.capacity-cell :person="$person" :week="$week" :cell="$person->weeks[$week]" :mine="$person->employee_id === $ownEmployee" /></td>
                        @endforeach
                    </tr>
                    @empty
                        <tr><td colspan="5" class="py-10 text-center text-muted-foreground">{{ $peopleSearch === '' ? 'Assign people to open work to plan their effort.' : 'No people match your search.' }}</td></tr>
                    @endforelse
                </tbody></table></div>
            <x-kit.pagination :paginator="$people" noun="people" class="!border-0 !px-0" />
        @endif
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
            <p>Availability covers all project work. Planned hours include only work you can access.</p>
            @if ($needsEstimate > 0)<span class="num">{{ number_format($needsEstimate) }} {{ $needsEstimate === 1 ? 'assignment without' : 'assignments without' }} estimates</span>
            @endif
        </div>
        @if ($issuesOpen)
            <flux:modal wire:model.self="issuesOpen" class="w-full max-w-2xl"><section class="flex max-h-[calc(100dvh-5rem)] min-h-0 flex-col gap-4"><flux:heading size="lg" level="2" class="pr-8">Estimates to review <span class="num text-muted-foreground">{{ $issues->total() }}</span></flux:heading><p class="text-xs text-muted-foreground">Parent work and former assignees are excluded from the totals. Move that effort to current leaf work or clear it.</p>
                <div class="min-h-0 divide-y divide-border overflow-y-auto overscroll-contain">@foreach ($issues as $issue)<div class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"><div class="min-w-0"><span class="block truncate text-sm font-medium" title="{{ $issue->title }}">{{ $issue->title }}</span><span class="text-xs text-muted-foreground">{{ $issue->person_name }} · {{ \Carbon\CarbonImmutable::parse($issue->week_start)->format('M j') }} · {{ PlanningHours::format((int) $issue->hours_hundredths) }}h</span></div>
                    @if ($issue->can_plan || ($issue->employee_id === $ownEmployee && $issue->on_crew))<flux:button size="sm" variant="ghost" wire:click="openEffort('{{ $issue->task_id }}', '{{ $issue->employee_id }}', '{{ $issue->week_start }}')">Review</flux:button>
                    @endif
                </div>
                @endforeach</div>
                <x-work.compact-pagination :paginator="$issues" noun="estimates" class="!px-0" />
            </section></flux:modal>
        @endif

        <flux:modal wire:model.self="weekOpen" class="w-full max-w-2xl"><div class="space-y-5"><div><flux:heading size="lg" level="2">{{ $personName }}</flux:heading><flux:text>Week of {{ $weekKey === '' ? '' : \Carbon\CarbonImmutable::parse($weekKey)->format('M j, Y') }}</flux:text></div>
            <flux:input wire:model.live.debounce.250ms="taskSearch" icon="magnifying-glass" placeholder="Find a task…" aria-label="Search weekly effort tasks" />
            @if ($weekTasks !== null)
                <div class="space-y-1">
                    @forelse ($weekTasks as $task)
                        <div wire:key="effort-task-{{ $task->id }}" class="space-y-3 border-b border-border py-3 last:border-0" x-data x-on:capacity-estimate-closed.window="if ($event.detail.taskId === '{{ $task->id }}') $nextTick(() => $refs.estimate?.focus())">
                            <div class="flex items-center justify-between gap-3"><div class="min-w-0"><a href="{{ $own ? url('/my-tasks?view=capacity&task='.$task->id) : route('projects.show', ['project' => $task->project_id, 'view' => 'capacity', 'task' => $task->id]) }}" wire:navigate class="block truncate text-sm font-medium hover:text-primary" title="{{ $task->title }}">{{ $task->title }}</a><span class="mt-1 block truncate text-xs text-muted-foreground">{{ $task->project_name }} · {{ $task->client_name ?? 'Internal' }}</span></div>
                                @if (! $effortInline || $effortTaskId !== $task->id)
                                    @if ($task->can_plan || $personId === $ownEmployee)<flux:button size="sm" variant="ghost" icon="pencil-square" x-ref="estimate" wire:click="openEffort('{{ $task->id }}', '{{ $personId }}', '{{ $weekKey }}')" aria-label="Estimate {{ $task->title }}">{{ $task->hours_hundredths === null ? 'Estimate' : PlanningHours::format((int) $task->hours_hundredths).'h' }}</flux:button>
                                    @else<span class="num shrink-0 text-sm">{{ $task->hours_hundredths === null ? '—' : PlanningHours::format((int) $task->hours_hundredths).'h' }}</span>
                                    @endif
                                @endif
                            </div>
                            @if ($effortInline && $effortTaskId === $task->id)
                                <form wire:submit="saveEffort" class="flex items-start gap-2" x-data x-init="$nextTick(() => { const input = $el.querySelector('input'); input?.focus(); input?.select(); })" x-on:keydown.escape.prevent.stop="$wire.cancelEffort()" aria-label="Effort estimate for {{ $task->title }}">
                                    <div class="min-w-0 flex-1"><flux:input wire:model="hours" aria-label="Planned hours" inputmode="decimal" placeholder="Hours; blank clears" /><flux:error name="hours" /></div>
                                    <flux:button type="submit" variant="primary" aria-label="Save estimate for {{ $task->title }}">Save</flux:button>
                                    <flux:button type="button" variant="ghost" square icon="x-mark" wire:click="cancelEffort" aria-label="Cancel estimate" />
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-muted-foreground">No visible open leaf tasks match.</p>
                    @endforelse
                </div>
                <x-work.compact-pagination :paginator="$weekTasks" noun="weekly tasks" class="!px-0" />
            @endif
        </div></flux:modal>

        <flux:modal wire:model.self="availabilityOpen" class="w-full max-w-md"><form wire:submit="saveAvailability" class="space-y-5"><div><flux:heading size="lg" level="2">My available project hours</flux:heading><flux:text>Week of {{ $availabilityWeek === '' ? '' : \Carbon\CarbonImmutable::parse($availabilityWeek)->format('M j, Y') }}</flux:text></div><flux:input wire:model="hours" label="Available hours" inputmode="decimal" placeholder="Not set" autofocus /><p class="text-xs text-muted-foreground">Across all project work this week. Blank clears; zero means no availability.</p><flux:checkbox wire:model="shared" label="Share this week with my project planners" /><p class="text-xs text-muted-foreground">Only current planners who can see work assigned to you can read shared hours.</p><div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('availabilityOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Save availability</flux:button></div></form></flux:modal>

        <flux:modal wire:model.self="effortOpen" class="w-full max-w-md"><form wire:submit="saveEffort" class="space-y-5"><div><flux:heading size="lg" level="2">{{ $effortCanSet ? 'Estimate effort' : 'Clear excluded estimate' }}</flux:heading><p class="mt-2 break-words text-sm">{{ $effortTitle }}</p><flux:text>Week of {{ $effortWeek === '' ? '' : \Carbon\CarbonImmutable::parse($effortWeek)->format('M j, Y') }}</flux:text></div>
            @if ($effortCanSet)<flux:input wire:model="hours" label="Planned hours" inputmode="decimal" placeholder="Not estimated" autofocus /><p class="text-xs text-muted-foreground">For this person and week. Blank clears the estimate.</p>
            @else<flux:text>This {{ $storedHours === null ? '' : PlanningHours::format($storedHours).'h ' }}estimate is excluded from the workload totals.</flux:text>
            @endif
            @if (! $effortCanSet)<flux:error name="hours" />
            @endif
            <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('effortOpen', false)">Cancel</flux:button><flux:button type="submit" :variant="$effortCanSet ? 'primary' : 'danger'">{{ $effortCanSet ? 'Save estimate' : 'Clear estimate' }}</flux:button></div></form></flux:modal>
    @endif
</section>
