{{--
    Files on a post, comment or message, as chips: an icon for the kind of
    file, its name, its size, and a download on click. Photos aren't here;
    they show as pictures.

    Usage:
        <x-social.files :files="$post->files" route="community.files" />
        <x-social.files :files="$m->files" route="chat.files" compact />
--}}
@use('App\Support\HrisFormat')
@props([
    'files' => [],
    'route',
    'compact' => false,
])

@if ($files !== [])
    <ul {{ $attributes->class(['flex flex-wrap gap-2', 'gap-1.5' => $compact]) }}>
        @foreach ($files as $file)
            <li wire:key="file-{{ $file->id }}" class="min-w-0 max-w-full">
                <a
                    href="{{ route($route, ['file' => $file->id]) }}"
                    download
                    @class([
                        'group/file flex min-w-0 max-w-72 items-center gap-2.5 rounded-lg border border-border bg-card text-left transition-colors hover:bg-accent',
                        'px-3 py-2' => ! $compact,
                        'px-2.5 py-1.5' => $compact,
                    ])
                    title="Download {{ $file->name }}"
                >
                    @include('components.social.file-icon', ['extension' => $file->extension()])
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{ $file->name }}</span>
                        <span class="block text-xs text-muted-foreground">{{ $file->label }} &middot; {{ HrisFormat::bytes($file->size) }}</span>
                    </span>
                    <flux:icon.arrow-down-tray class="size-4 shrink-0 text-muted-foreground transition-colors group-hover/file:text-foreground" />
                </a>
            </li>
        @endforeach
    </ul>
@endif
