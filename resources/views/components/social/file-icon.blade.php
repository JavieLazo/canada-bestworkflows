{{--
    The small badge that says what kind of file it is, coloured the way
    people already know them: red for PDF, blue for Word, green for Excel and
    CSV, orange for PowerPoint. Uses the app's own tones.

    Expects: $extension ("pdf", "docx", ...).
--}}
@php
    $look = match ($extension) {
        'pdf' => ['PDF', 'bg-danger-soft text-danger'],
        'docx' => ['DOC', 'bg-primary-soft text-primary'],
        'xlsx', 'csv' => [$extension === 'csv' ? 'CSV' : 'XLS', 'bg-success-soft text-success'],
        'pptx' => ['PPT', 'bg-warning-soft text-warning'],
        default => ['TXT', 'bg-muted text-muted-foreground'],
    };
@endphp
<span class="flex size-9 shrink-0 items-center justify-center rounded-md text-[0.6rem] font-bold tracking-wide {{ $look[1] }}" aria-hidden="true">{{ $look[0] }}</span>
