@props(['projects', 'view'])
<div class="space-y-4"><p class="text-xs text-muted-foreground">Projects included in this summary</p><ul class="divide-y divide-border">@forelse ($projects ?? [] as $row)<li><a href="{{ route('projects.show', ['project' => $row->id, 'view' => $view]) }}" class="flex min-w-0 items-center gap-3 rounded-lg py-3 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" wire:navigate><span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">{{ $row->name }}</span><span class="mt-1 block truncate text-xs text-muted-foreground">{{ $row->client_name ?? 'Internal' }}</span></span><flux:icon.arrow-up-right class="size-4" /></a></li>
    @empty<li class="py-4 text-sm text-muted-foreground">No projects are currently available.</li>
    @endforelse
    </ul>@if ($projects !== null)<x-kit.pagination :paginator="$projects" noun="projects" />
    @endif
</div>
