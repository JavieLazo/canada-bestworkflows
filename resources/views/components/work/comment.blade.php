@props(['comment'])
@php
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
@endphp
<article {{ $attributes->class('flex gap-3 py-3') }}>
    <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium" aria-hidden="true">{{ mb_substr($comment->author_name, 0, 1) }}</span>
    <div class="min-w-0 flex-1 space-y-1">
        <div class="flex flex-wrap items-baseline justify-between gap-2"><span class="text-sm font-medium">{{ $comment->author_name }}</span><time class="num text-xs text-muted-foreground">{{ ProjectsData::fmtDateTime($comment->created_at) }}</time></div>
        @if ($comment->event === 'BRIEF_UPDATED')
            <details><summary class="w-fit cursor-pointer text-xs text-muted-foreground">Updated task brief · previous version</summary><p class="mt-2 whitespace-pre-wrap break-words rounded-lg bg-muted/40 p-3 text-sm">{{ $comment->body }}</p></details>
        @else
            @if ($comment->event)<span class="text-xs text-muted-foreground">{{ TaskPolicy::statusLabel($comment->event) }}</span>
@endif
            <p class="whitespace-pre-wrap break-words text-sm">{{ $comment->body }}</p>
        @endif
    </div>
</article>
