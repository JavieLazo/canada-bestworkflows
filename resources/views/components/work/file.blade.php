@props(['file', 'href'])
@use('App\Support\HrisFormat')
<div {{ $attributes->class('flex min-w-0 items-center gap-2 rounded-lg border border-border bg-card p-2.5') }}>
    <a href="{{ $href }}" class="flex min-w-0 flex-1 items-center gap-2.5 rounded focus-visible:outline-2 focus-visible:outline-primary" aria-label="Download {{ $file->file_name }}">
        @include('components.social.file-icon', ['extension' => mb_strtolower(pathinfo($file->file_name, PATHINFO_EXTENSION))])
        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium" title="{{ $file->file_name }}">{{ $file->file_name }}</span><span class="num block text-xs text-muted-foreground">{{ HrisFormat::bytes($file->size ?? $file->file_size) }}{{ $meta ?? '' }}</span></span>
        <flux:icon.arrow-down-tray class="size-4 shrink-0 text-muted-foreground" />
    </a>
    {{ $actions ?? '' }}
</div>
