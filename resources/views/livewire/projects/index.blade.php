{{--
    /projects — the port of projects-page.tsx. Same heading copy, the same five
    work-queue stat cards (which double as queue filters), the same filter row,
    the same seven columns in the same order, the same empty state, and the
    same New-project dialog (new-project-dialog.tsx).

    Icons are the reference's own Lucide set, inlined through
    livewire/projects/partials/icon.blade.php (Flux ships Heroicons; the
    reference look is Lucide, exactly as the shell's module icons are inlined).
    Inside a flux:button the include goes in the named icon slot, never the
    default slot: Flux wraps the default slot in a bare span and preflight makes
    an svg display:block, so an inline icon there stacks above the label.

    The per-project manager (projects.manager_id, a user account) is shown to
    people as the "Task Leader"; the identifiers keep the reference's name.
--}}
@php
    use App\Domain\Projects\ProjectPolicy;
    use App\Livewire\Projects\ProjectsData;
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Project Management</h1>
            <p class="text-sm text-muted-foreground">
                The projects management runs and the tasks inside them. Open one to hand out work and
                review what comes back.
            </p>
        </div>
        <div class="flex items-center gap-3">
            <flux:button variant="outline" href="{{ $exportUrl }}">
                <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'download', 'class' => 'size-4'])</x-slot:icon>
                Export CSV
            </flux:button>
            <flux:button variant="primary" wire:click="$set('adding', true)">
                <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'plus', 'class' => 'size-4'])</x-slot:icon>
                New project
            </flux:button>
        </div>
    </div>

    <section aria-label="Project work queue" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <button
            type="button"
            aria-pressed="{{ $queue === 'ALL' ? 'true' : 'false' }}"
            wire:click="setQueue('ALL')"
            class="hris-card flex items-center gap-3 p-4 text-left transition-colors hover:bg-accent"
        >
            <span class="rounded-md bg-primary/10 p-2 text-primary">
                @include('livewire.projects.partials.icon', ['icon' => 'clock-3', 'class' => 'size-4'])
            </span>
            <span>
                <span class="num block text-xl font-bold">{{ $activeProjects }}</span>
                <span class="text-xs text-muted-foreground">Active projects</span>
            </span>
        </button>
        <button
            type="button"
            aria-pressed="{{ $queue === 'REVIEW' ? 'true' : 'false' }}"
            wire:click="setQueue('REVIEW')"
            class="hris-card flex items-center gap-3 p-4 text-left transition-colors hover:bg-accent"
        >
            <span class="rounded-md bg-success/10 p-2 text-success">
                @include('livewire.projects.partials.icon', ['icon' => 'clipboard-check', 'class' => 'size-4'])
            </span>
            <span>
                <span class="num block text-xl font-bold">{{ $reviewCount }}</span>
                <span class="text-xs text-muted-foreground">Needs review</span>
            </span>
        </button>
        <button
            type="button"
            aria-pressed="{{ $queue === 'OVERDUE' ? 'true' : 'false' }}"
            wire:click="setQueue('OVERDUE')"
            class="hris-card flex items-center gap-3 p-4 text-left transition-colors hover:bg-accent"
        >
            <span class="rounded-md bg-danger/10 p-2 text-danger">
                @include('livewire.projects.partials.icon', ['icon' => 'alert-triangle', 'class' => 'size-4'])
            </span>
            <span>
                <span class="num block text-xl font-bold">{{ $overdueCount }}</span>
                <span class="text-xs text-muted-foreground">Overdue tasks</span>
            </span>
        </button>
        <button
            type="button"
            aria-pressed="{{ $queue === 'DUE_SOON' ? 'true' : 'false' }}"
            wire:click="setQueue('DUE_SOON')"
            class="hris-card flex items-center gap-3 p-4 text-left transition-colors hover:bg-accent"
        >
            <span class="rounded-md bg-warning/10 p-2 text-warning">
                @include('livewire.projects.partials.icon', ['icon' => 'clock-3', 'class' => 'size-4'])
            </span>
            <span>
                <span class="num block text-xl font-bold">{{ $dueSoonCount }}</span>
                <span class="text-xs text-muted-foreground">Due soon</span>
            </span>
        </button>
        <button
            type="button"
            aria-pressed="{{ $queue === 'UNASSIGNED' ? 'true' : 'false' }}"
            wire:click="setQueue('UNASSIGNED')"
            class="hris-card flex items-center gap-3 p-4 text-left transition-colors hover:bg-accent"
        >
            <span class="rounded-md bg-muted p-2 text-muted-foreground">
                @include('livewire.projects.partials.icon', ['icon' => 'user-round-x', 'class' => 'size-4'])
            </span>
            <span>
                <span class="num block text-xl font-bold">{{ $unassignedCount }}</span>
                <span class="text-xs text-muted-foreground">Unassigned tasks</span>
            </span>
        </button>
    </section>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                aria-label="Search projects"
                placeholder="Search code or project name"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />

            <x-kit.filter-select label="Filter by status" class="w-[13rem]" wire:model.live="status">
                <flux:select.option value="ALL">All statuses</flux:select.option>
                @foreach (ProjectPolicy::STATUSES as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>

            <x-kit.filter-select label="Filter by site" class="w-[13rem]" wire:model.live="site">
                <flux:select.option value="ALL">All sites</flux:select.option>
                @foreach ($sites as $siteRow)
                    <flux:select.option value="{{ $siteRow->id }}">{{ $siteRow->name }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>

            <x-kit.filter-select label="Filter by task leader" class="w-[13rem]" wire:model.live="manager">
                <flux:select.option value="ALL">All task leaders</flux:select.option>
                @foreach ($accounts as $account)
                    @if ((bool) $account->getAttribute('is_active'))
                        <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                    @endif
                @endforeach
            </x-kit.filter-select>

            <x-kit.filter-select label="Filter by queue" class="w-[13rem]" wire:model.live="queue">
                <flux:select.option value="ALL">All work</flux:select.option>
                <flux:select.option value="REVIEW">Needs review</flux:select.option>
                <flux:select.option value="OVERDUE">Overdue</flux:select.option>
                <flux:select.option value="DUE_SOON">Due soon</flux:select.option>
                <flux:select.option value="UNASSIGNED">Unassigned</flux:select.option>
            </x-kit.filter-select>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="code" label="Code" />
                        <x-kit.sort-header field="name" label="Project" />
                        <x-kit.sort-header field="manager" label="Task Leader" />
                        <th>Timeline</th>
                        <th>Progress</th>
                        <th>Health</th>
                        <x-kit.sort-header field="status" label="Status" />
                    </tr>
                </thead>
                <tbody>
                    @if ($visibleCount === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="7" title="No project matches those filters." />
                        </tr>
                    @endif
                    @foreach ($rows as $project)
                        @php
                            $projectTasks = $tasksByProject[$project->id] ?? [];
                            $progress = ProjectPolicy::progress(ProjectsData::taskShapes($projectTasks));
                            $siteName = $sites->firstWhere('id', $project->site_id)?->name ?? '—';
                            $health = ProjectPolicy::health(
                                ProjectsData::projectShape($project),
                                ProjectsData::taskShapes($projectTasks),
                                $today,
                            );
                            $managerName = $project->manager_id === null
                                ? null
                                : $accounts->firstWhere('id', $project->manager_id)?->name;
                            $detailUrl = route('projects.show', $project->id);
                        @endphp
                        <tr
                            wire:key="project-{{ $project->id }}"
                            tabindex="0"
                            aria-label="Open {{ $project->name }}"
                            class="cursor-pointer focus:outline-none focus-visible:bg-accent"
                            x-on:click="window.location.assign(@js($detailUrl))"
                            x-on:keydown.enter.prevent="window.location.assign(@js($detailUrl))"
                            x-on:keydown.space.prevent="window.location.assign(@js($detailUrl))"
                        >
                            <td class="num whitespace-nowrap">{{ $project->code }}</td>
                            <td>
                                <p class="font-medium">{{ $project->name }}</p>
                                <p class="text-xs text-muted-foreground">{{ $siteName }}</p>
                            </td>
                            <td>{{ $managerName ?? '—' }}</td>
                            <td class="num whitespace-nowrap">
                                {{ ProjectsData::fmtDate($project->start_date) }} &ndash; {{ $project->due_date ? ProjectsData::fmtDate($project->due_date) : 'open' }}
                            </td>
                            <td>
                                @if ($progress['total'] === 0)
                                    <span class="text-xs text-muted-foreground">No tasks yet</span>
                                @else
                                    <div class="flex items-center gap-2">
                                        {{-- shadcn <Progress> — a plain track/bar pair. --}}
                                        <div class="h-2 w-28 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}">
                                            <div class="h-full rounded-full bg-primary transition-all" style="width: {{ $progress['percent'] }}%"></div>
                                        </div>
                                        <span class="num text-xs text-muted-foreground">
                                            {{ $progress['completed'] }}/{{ $progress['total'] }}
                                        </span>
                                    </div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                <span class="{{ ProjectPolicy::healthPill($health) }}">
                                    {{ ProjectPolicy::healthLabel($health) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap">
                                <span class="{{ ProjectPolicy::statusPill($project->status) }}">
                                    {{ ProjectPolicy::statusLabel($project->status) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$rows" noun="projects" />
    </section>

    {{-- new-project-dialog.tsx --}}
    <flux:modal wire:model.self="adding" class="max-h-[90vh] w-full max-w-lg overflow-y-auto">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">New project</flux:heading>
                <flux:text class="mt-1">
                    The project is the container for its tasks. Its code is assigned automatically, and
                    tasks are handed out from the project itself.
                </flux:text>
            </div>

            <form class="space-y-4" wire:submit="createProject">
                <div class="space-y-2">
                    <flux:label>Name</flux:label>
                    <flux:input
                        required
                        wire:model="draftName"
                        placeholder="What the project is called"
                    />
                </div>

                <div class="space-y-2">
                    <flux:label>Description</flux:label>
                    <flux:textarea
                        rows="2"
                        wire:model="draftDescription"
                        placeholder="Optional — what the project covers"
                    />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <flux:label>Site</flux:label>
                        <flux:select aria-label="Site" wire:model="draftSiteId">
                            <flux:select.option value="NONE">No site</flux:select.option>
                            @foreach ($sites as $siteRow)
                                <flux:select.option value="{{ $siteRow->id }}">{{ $siteRow->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="space-y-2">
                        <flux:label>Status</flux:label>
                        <flux:select aria-label="Status" wire:model="draftStatus">
                            @foreach (ProjectPolicy::STATUSES as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="space-y-2">
                        <flux:label>Start date</flux:label>
                        <x-kit.date-field
                            required
                            class="num"
                            wire:model="draftStartDate"
                            aria-label="Start date"
                        />
                    </div>

                    <div class="space-y-2">
                        <flux:label>Due date</flux:label>
                        <x-kit.date-field
                            class="num"
                            wire:model="draftDueDate"
                            aria-label="Due date"
                        />
                    </div>
                </div>

                <div class="space-y-2">
                    <flux:label>Task Leader</flux:label>
                    <flux:select aria-label="Task Leader" wire:model="draftManagerId">
                        <flux:select.option value="NONE">No task leader</flux:select.option>
                        @foreach ($accounts as $account)
                            @if ((bool) $account->getAttribute('is_active'))
                                {{-- A deactivated account is signed out on sight, so it
                                     can't be accountable for a project. --}}
                                <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                            @endif
                        @endforeach
                    </flux:select>
                </div>

                <flux:button type="submit" variant="primary">
                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'plus', 'class' => 'size-4'])</x-slot:icon>
                    Create project
                </flux:button>
            </form>
        </div>
    </flux:modal>
</div>
