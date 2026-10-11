{{--
    A small round photo on an Organization card: the person's initials, with
    their portrait drawn over them when they have one. Decorative — the name
    beside it, or the list in its title, says who it is.

    @include('livewire.employees.partials.org-photo', ['person' => $person, 'class' => 'size-9 text-xs'])
--}}
@php
    use App\Platform\Files\PortraitUrl;

    $url = PortraitUrl::for($person->photoPath);
@endphp
<span class="relative flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted num font-semibold text-foreground {{ $class }}" aria-hidden="true">
    {{ $person->initials }}
    @if ($url !== null)
        <img src="{{ $url }}" alt="" class="absolute inset-0 h-full w-full object-cover">
    @endif
</span>
