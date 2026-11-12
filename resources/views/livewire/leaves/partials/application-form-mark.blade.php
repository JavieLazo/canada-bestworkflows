{{--
    One choice of a FILED Application for Leave: drawn like the form's radio,
    but a static mark, because a filed application is only read. `$on` says it
    was the choice made, `$label` names it and `$citation` is its legal basis,
    or null.
--}}
<span class="flex items-center gap-3" @if ($on) data-filed-choice @endif>
    <span @class(['flex size-[1.125rem] shrink-0 items-center justify-center rounded-full border', 'border-primary bg-primary' => $on, 'border-border' => ! $on]) aria-hidden="true">
        @if ($on)<span class="size-2 rounded-full bg-primary-foreground"></span>@endif
    </span>
    <span @class(['text-sm', 'font-medium' => $on, 'text-muted-foreground' => ! $on])>@if ($on)<span class="sr-only">Selected: </span>@endif{{ $label }}@if ($citation !== null) <span class="ml-1 text-[11px] font-normal text-muted-foreground">({{ $citation }})</span>@endif</span>
</span>
