{{--
    One person in an opened department's table, drawn like the Employees
    list: the round avatar (portrait, or initials), the name, and the
    position. The name links to the record only when the viewer may open it.
    `$inside` indents a row under a group row; `$place` keys it, because a
    department's own people and its units' are separate groups.
--}}
@php
    use App\Platform\Files\PortraitUrl;

    $url = PortraitUrl::for($person->photoPath);
@endphp
<tr @class(['hris-org-in' => $inside]) wire:key="org-person-{{ $place }}-{{ $person->id }}">
    <td>
        <div class="hris-org-emp flex items-center gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                @if ($url !== null)
                    <img src="{{ $url }}" alt="{{ $person->name }}" class="h-full w-full object-cover">
                @else
                    <span class="num text-xs font-semibold">{{ $person->initials }}</span>
                @endif
            </span>
            @if ($person->canOpen)
                <a href="{{ route('employees.show', $person->id) }}" wire:navigate class="font-medium hover:underline">{{ $person->name }}</a>
            @else
                <span class="font-medium">{{ $person->name }}</span>
            @endif
        </div>
    </td>
    <td class="text-muted-foreground">{!! str_replace('/', '/<wbr>', e($person->position)) !!}</td>
</tr>
