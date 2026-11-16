@php
    use App\Domain\Projects\ProjectPolicy;
@endphp
<div class="ppm-library" aria-label="Project cards">
    @foreach ($rows as $project)
        @php
            $progress = $totalsByProject[$project->id]->progress();
        @endphp
        <article class="ppm-surface ppm-project-card" wire:key="project-card-{{ $project->id }}">
            <a href="{{ route('projects.show', $project->id) }}" aria-label="Open {{ $project->name }}" class="focus:outline-none">
                <div class="flex items-center justify-between gap-3">
                    <span class="num text-xs text-muted-foreground">{{ $project->code }}</span>
                    <span class="{{ ProjectPolicy::statusPill($project->status) }}">{{ ProjectPolicy::statusLabel($project->status) }}</span>
                </div>
                <div class="min-w-0 flex-1 space-y-2">
                    <h2 class="line-clamp-2 break-words" title="{{ $project->name }}">{{ $project->name }}</h2>
                    <x-work.project-context :client="$project->client_id === null ? null : $clients->get($project->client_id)" class="w-full" />
                </div>
                <x-work.progress :progress="$progress" />
                <div class="flex items-center justify-between gap-3 text-xs text-muted-foreground">
                    <span class="num flex shrink-0 items-center gap-1.5">
                        <flux:icon.calendar-days class="size-3.5" />
                        @if ($project->due_date)<x-work.date :date="$project->due_date" :today="$today" />@else No deadline @endif
                    </span>
                </div>
            </a>
        </article>
    @endforeach
</div>
