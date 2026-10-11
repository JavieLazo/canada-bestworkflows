{{--
    A group's "Photos & files" tab: everything shared in its posts and their
    comments, newest first. A photo opens in the viewer with the rest; a file
    downloads.

    Expects: $group (GroupView), $media (['photos' => ids, 'files' => FileView list]).
--}}
@php
    $urls = array_map(static fn (string $id): string => route('community.photos', ['photo' => $id]), $media['photos']);
@endphp

<div class="space-y-4">
    <section class="hris-card p-5" aria-labelledby="group-photos">
        <h3 id="group-photos" class="text-sm font-semibold">Photos <span class="num font-normal text-muted-foreground">{{ count($urls) }}</span></h3>
        @if ($urls === [])
            <p class="mt-2 text-sm text-muted-foreground">Photos shared in {{ $group->name }} will collect here.</p>
        @else
            <div class="mt-3 grid grid-cols-3 gap-1.5 sm:grid-cols-4 md:grid-cols-5">
                @foreach ($urls as $k => $src)
                    <button
                        type="button"
                        wire:key="group-photo-{{ $k }}"
                        x-on:click="$dispatch('lightbox', { images: @js($urls), index: {{ $k }} })"
                        class="block aspect-square cursor-pointer overflow-hidden rounded-md bg-muted"
                        aria-label="Open photo {{ $k + 1 }} of {{ count($urls) }}"
                    >
                        <img src="{{ $src }}" alt="" loading="lazy" class="size-full object-cover" />
                    </button>
                @endforeach
            </div>
        @endif
    </section>

    <section class="hris-card p-5" aria-labelledby="group-files">
        <h3 id="group-files" class="text-sm font-semibold">Files <span class="num font-normal text-muted-foreground">{{ count($media['files']) }}</span></h3>
        @if ($media['files'] === [])
            <p class="mt-2 text-sm text-muted-foreground">Files shared in {{ $group->name }} will collect here.</p>
        @else
            <x-social.files :files="$media['files']" route="community.files" class="mt-3" />
        @endif
    </section>
</div>
