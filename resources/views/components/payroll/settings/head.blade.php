{{--
    The head of a Payroll Settings card, as every card of the system opens:
    the card's title, one short line under it and, on the right, the card's
    own buttons (the slot). One component, so a reader finds the title and
    the action in the same place on every settings page.

    `heading-id` names the title for the card's `aria-labelledby`; `note`
    is a slot for a line that holds markup (pills, links) in place of the
    plain `description`; `flush` leaves out the rule under the head for a
    card whose body opens with a rule of its own.
--}}
@props([
    'title',
    'headingId' => null,
    'description' => null,
    'flush' => false,
])

<header {{ $attributes->class(['flex flex-wrap items-start justify-between gap-3 px-5 py-4', 'border-b border-border' => ! $flush]) }}>
    <div class="min-w-0">
        <h2 @if ($headingId !== null) id="{{ $headingId }}" @endif class="text-base font-bold">{{ $title }}</h2>
        @if (isset($note))
            <div class="mt-1 text-sm text-muted-foreground">{{ $note }}</div>
        @elseif ($description !== null)
            <p class="mt-1 text-sm text-muted-foreground">{{ $description }}</p>
        @endif
    </div>
    @if (trim((string) $slot) !== '')
        <div class="flex flex-wrap items-center gap-2 print:hidden">{{ $slot }}</div>
    @endif
</header>
