{{--
    Who the timeline below belongs to, as their record reads TODAY: what the
    Employee Records list shows of a person, plus the date they joined. It is
    not history and does not follow the filters — the filters narrow the list
    of changes only.
    Position level, worksite and pay are left to the entries, where each value
    carries the date it was saved.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;
    use App\Support\EmployeeIdentity;
    use App\Support\EmployeeName;

    $identity = EmployeeIdentity::besideName($subject);
    $joined = $subject->date_hired === null ? null : 'Joined '.$subject->date_hired->format('d M Y');
    $status = (string) $subject->employment_status;
@endphp
<section
    wire:key="employment-subject-{{ $subject->id }}"
    data-subject
    aria-label="Employee"
    class="hris-card flex flex-wrap items-center gap-x-4 gap-y-3 p-5"
>
    <span class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-primary-soft text-primary">
        @if ($subjectPhoto)
            <img src="{{ $subjectPhoto }}" alt="{{ $subject->name }}" class="size-full object-cover" />
        @else
            <span class="num text-lg font-semibold" aria-hidden="true">{{ EmployeeName::initials($subject) }}</span>
        @endif
    </span>

    {{-- The floor width sends the pills to their own line on a phone instead of squeezing the name. --}}
    <div class="min-w-[11rem] flex-1">
        <h2 class="flex flex-wrap items-center gap-2 text-lg font-bold leading-tight">
            @if ($own ?? false)
                <span class="break-words">{{ $subject->name }}</span>
            @else
                <a href="{{ route('employees.show', $subject->id) }}" class="break-words hover:text-primary hover:underline">{{ $subject->name }}</a>
            @endif
            {{-- The Employee ID or nothing: an empty pill is a grey smudge beside the name. --}}
            @if ($identity !== '')
                <span class="num pill pill-muted">{{ $identity }}</span>
            @endif
        </h2>
        <p class="mt-1 break-words text-sm text-muted-foreground">{{ EmployeeIdentity::meta($subject->position, $joined) }}</p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @if ($status !== '')
            <span class="{{ EmployeeFields::isRegular($status) ? 'pill pill-success' : 'pill pill-info' }}">
                {{ EmployeeFields::employmentStatusLabel($status) }}
            </span>
        @endif
        @unless ($subject->is_active)
            <span class="pill pill-muted">Inactive</span>
        @endunless
    </div>
</section>
