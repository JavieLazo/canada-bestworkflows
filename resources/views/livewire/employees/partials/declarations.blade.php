{{--
    The Declarations card's body, read or edit: the twelve questions in sheet
    order, worded as the Personal Data Sheet words them, with no item or part
    numbers.

    Expects:
        $section          EmployeeFields::section('declarations'): each answer
                          (`answer`) followed by the details it keeps for a Yes
                          (`when` names the answer)
        $employee         the record (read mode)
        $editing          bool
        $formState        the Livewire form array (edit mode)
        $fieldErrors      field-keyed messages
        $headingTag       taken like the other body partials; the card draws no
                          heading of its own
        $referenceOptions, $sites — forwarded to partials/field

    The header above it (partials/section-header, title for screen readers
    only, the description line, Edit) is the section partial's.

    Read mode is a <dl>: a question that opens a group (DECLARATION_STEMS)
    first spans the card as a <dt> of its own, then each question reads
    "Not answered" (muted: NULL is never No), "No", or "Yes" followed by
    one line per detail given (EmployeeFields::declarationText). A detail
    has no read row of its own.

    Edit mode asks each question with a Not answered / Yes / No select, and
    draws the question's details under it only while Yes is chosen
    (EmployeeFields::shown on the live round trip). UpdateEmployee clears
    the details of an answer that is not Yes.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;
@endphp
@if (! $editing)
    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
        @foreach ($section['fields'] as $field)
            @continue(! EmployeeFields::isAnswer($field))
            @php
                $key = (string) $field['key'];
                $declaration = EmployeeFields::declarationText($employee, $key);
            @endphp
            @if (isset(EmployeeFields::DECLARATION_STEMS[$key]))
                <div class="min-w-0 sm:col-span-2">
                    <dt class="break-words text-sm text-muted-foreground">{{ EmployeeFields::DECLARATION_STEMS[$key] }}</dt>
                </div>
            @endif
            <div class="min-w-0 {{ ! empty($field['wide']) ? 'sm:col-span-2' : '' }}">
                <dt class="break-words text-sm text-muted-foreground">{{ $field['label'] }}</dt>
                @if ($declaration['answer'] === null)
                    <dd class="mt-1 break-words text-sm text-muted-foreground">Not answered</dd>
                @else
                    <dd class="mt-1 break-words text-sm font-medium{{ str_contains($declaration['text'], "\n") ? ' whitespace-pre-line' : '' }}">{{ $declaration['text'] }}</dd>
                @endif
            </div>
        @endforeach
    </dl>
@else
    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        @foreach ($section['fields'] as $field)
            @continue(! EmployeeFields::isAnswer($field))
            @php
                $key = (string) $field['key'];
                $error = $fieldErrors[$key] ?? null;
            @endphp
            @if (isset(EmployeeFields::DECLARATION_STEMS[$key]))
                <p class="break-words text-sm text-muted-foreground sm:col-span-2">{{ EmployeeFields::DECLARATION_STEMS[$key] }}</p>
            @endif
            <div wire:key="declaration-{{ $key }}" class="space-y-2 {{ ! empty($field['wide']) ? 'sm:col-span-2' : '' }}">
                <x-kit.select
                    wire:model.live="form.{{ $key }}"
                    label="{{ $field['label'] }}"
                    :invalid="(bool) $error"
                >
                    @foreach (EmployeeFields::ANSWERS as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </x-kit.select>

                @if ($error)
                    <p class="text-xs text-danger">{{ $error }}</p>
                @endif

                @foreach ($section['fields'] as $detail)
                    @continue(($detail['when'][0] ?? null) !== $key || ! EmployeeFields::shown($detail, $formState))
                    <div wire:key="declaration-detail-{{ $detail['key'] }}">
                        @include('livewire.employees.partials.field', [
                            'field' => $detail,
                            'editing' => true,
                            'display' => '',
                            'formState' => $formState,
                            'referenceOptions' => $referenceOptions,
                            'sites' => $sites,
                            'error' => $fieldErrors[$detail['key']] ?? null,
                            'required' => false,
                            'legacy' => '',
                        ])
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
