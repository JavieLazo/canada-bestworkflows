{{--
    What's waiting on a draft before it's sent: photos as small squares (a
    click opens them in the photo viewer), files as chips. Each has an x to
    take it off again.

    `draft` is the draft's slot in WithStagedAttachments.

    Usage:
        <x-social.staged draft="post" :files="$staged['post'] ?? []" />
        <x-social.staged :draft="'r-'.$comment->id" :files="$staged['r-'.$comment->id] ?? []" compact />
--}}
@use('App\Modules\Social\Application\Attachments')
@use('App\Support\HrisFormat')
@props([
    'draft',
    'files' => [],
    'compact' => false,
])
@php
    $photos = [];
    $documents = [];
    foreach ($files as $i => $file) {
        $found = Attachments::identify($file);
        if ($found !== null && $found[0] === 'PHOTO') {
            try {
                $photos[$i] = $file->temporaryUrl();
            } catch (\Throwable) {
                $photos[$i] = null;
            }
        } else {
            $documents[$i] = $file;
        }
    }
    $previews = array_values(array_filter($photos));
    $remove = 'absolute right-1 top-1 inline-flex size-5 cursor-pointer items-center justify-center rounded-full bg-black/60 text-white transition-colors hover:bg-black/80';
@endphp

@if ($files !== [])
    <div {{ $attributes->class('space-y-2') }}>
        @if ($photos !== [])
            <div class="flex flex-wrap gap-2">
                @foreach ($photos as $i => $url)
                    <div wire:key="staged-{{ $draft }}-{{ $i }}-{{ $files[$i]->getFilename() }}" @class(['relative shrink-0 overflow-hidden rounded-md border border-border bg-muted', 'size-20' => ! $compact, 'size-14' => $compact])>
                        @if ($url !== null)
                            <button
                                type="button"
                                x-on:click="$dispatch('lightbox', { images: @js($previews), index: {{ max(0, array_search($url, $previews, true)) }}, download: false })"
                                class="block size-full cursor-pointer"
                                aria-label="Look at photo {{ $loop->iteration }}"
                            >
                                <img src="{{ $url }}" alt="" class="size-full object-cover" />
                            </button>
                        @endif
                        <button type="button" wire:click="unstage('{{ $draft }}', {{ $i }})" class="{{ $remove }}" aria-label="Remove photo {{ $loop->iteration }}">
                            <flux:icon.x-mark class="size-3" />
                        </button>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($documents !== [])
            <ul class="flex flex-wrap gap-2">
                @foreach ($documents as $i => $file)
                    <li wire:key="staged-{{ $draft }}-{{ $i }}-{{ $file->getFilename() }}" class="flex min-w-0 max-w-64 items-center gap-2 rounded-lg border border-border bg-card py-1.5 pl-2 pr-1">
                        @include('components.social.file-icon', ['extension' => strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION))])
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-xs font-medium">{{ $file->getClientOriginalName() }}</span>
                            <span class="block text-[0.7rem] text-muted-foreground">{{ HrisFormat::bytes((int) $file->getSize()) }}</span>
                        </span>
                        <button type="button" wire:click="unstage('{{ $draft }}', {{ $i }})" class="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground" aria-label="Remove {{ $file->getClientOriginalName() }}">
                            <flux:icon.x-mark class="size-3.5" />
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
