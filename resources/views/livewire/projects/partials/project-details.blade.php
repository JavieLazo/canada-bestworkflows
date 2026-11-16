@use('App\Livewire\Projects\ProjectsData')
<section class="space-y-4" aria-label="Project facts">
    <dl class="space-y-3 text-sm">
        <div class="ppm-project-fact">
            <dt class="ppm-property-label inline-flex items-center gap-2"><flux:icon.building-office-2 class="size-4" aria-hidden="true" />Client</dt>
            <dd class="min-w-0 break-words">@if ($client !== null)<a href="{{ route('projects.index', ['client' => $client->id, 'portfolio' => 'ALL']) }}" wire:navigate class="rounded hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">{{ $client->name }}</a>@else Internal @endif</dd>
        </div>
        <div class="ppm-project-fact"><dt class="ppm-property-label inline-flex items-center gap-2"><flux:icon.user-circle class="size-4" aria-hidden="true" />Project lead</dt><dd class="min-w-0 break-words">{{ $accountName($project->manager_id) }}</dd></div>
        <div class="ppm-project-fact"><dt class="ppm-property-label inline-flex items-center gap-2"><flux:icon.map-pin class="size-4" aria-hidden="true" />Site</dt><dd class="min-w-0 break-words">{{ $siteName }}</dd></div>
    </dl>
    <dl class="grid grid-cols-2 gap-4 rounded-lg bg-muted/40 px-4 py-3 text-sm" aria-label="Project schedule">
        <div class="min-w-0"><dt class="ppm-property-label inline-flex items-center gap-1.5"><flux:icon.calendar-days class="size-3.5" aria-hidden="true" />Starts</dt><dd class="num mt-1">{{ ProjectsData::fmtDate($project->start_date) }}</dd></div>
        <div class="min-w-0"><dt class="ppm-property-label inline-flex items-center gap-1.5"><flux:icon.calendar-days class="size-3.5" aria-hidden="true" />Deadline</dt><dd class="mt-1"><x-work.project-deadline :project="$project" :today="$today" :icon="false" /></dd></div>
    </dl>
    <p class="num flex items-center gap-1.5 text-xs text-muted-foreground"><flux:icon.clock class="size-3.5" aria-hidden="true" />Created {{ ProjectsData::fmtDateManila($project->created_at) }}</p>
</section>
