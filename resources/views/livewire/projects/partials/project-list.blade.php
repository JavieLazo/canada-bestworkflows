@php
    use App\Domain\Projects\ProjectPolicy;
    use App\Livewire\Projects\ProjectsData;
@endphp
<div class="hris-table-wrap hris-table-scroll">
    <table class="hris-table ppm-table">
        <thead>
            <tr>
                <x-kit.sort-header field="code" label="Code" />
                <x-kit.sort-header field="name" label="Project" />
                <th>Client</th>
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
                    $progress = $totalsByProject[$project->id]->progress();
                    $siteName = $sites->firstWhere('id', $project->site_id)?->name ?? '—';
                    $health = $totalsByProject[$project->id]->health($project, $today);
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
                    <td class="max-w-56"><x-work.project-context :client="$project->client_id === null ? null : $clients->get($project->client_id)" /></td>
                    <td class="num whitespace-nowrap">
                        {{ ProjectsData::fmtDate($project->start_date) }} &ndash; {{ $project->due_date ? ProjectsData::fmtDate($project->due_date) : 'open' }}
                    </td>
                    <td>
                        <x-work.progress :progress="$progress" class="w-36" />
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
