{{--
    Add-employee wizard — port of add-employee-dialog.tsx:206-329.

    Numbered dots joined by a rail (labels stay out of it), the heading
    naming the step, a 2-column field grid, and Back / Next / Create
    employee in the footer. Thirteen steps (EmployeeFields::WIZARD); the
    dots show from sm up, and below it the "Step N of 13" line and the step
    title carry the place. A step draws record sections or Personal Data
    Sheet lists:
    - Addresses: partials/addresses, the record page's own partial, with its
      group headings one level under this step's h3 and the residential
      group marked required.
    - Family (the spouse, the parents, the children) and Other Information
      (three line lists) hold several sections, each under its own h4; the
      parents' groups sit one level further down.
    - Declarations: partials/declarations in edit mode, each question's
      details drawn only while Yes is chosen.
    - The five list steps: partials/pds/wizard-rows, one bordered block per
      row with the record dialog's fields, added and removed inline. They
      may be left empty.
    Elsewhere a field that waits on another answer (Specify, the dual
    citizenship parts) is drawn only while that answer is picked. The
    children are rows the field partial adds and removes through
    addChild() / removeChild(), the Other info lines through addItem() /
    removeItem().
--}}
@php
    use App\Livewire\Employees\EmployeeFields;
@endphp
<div>
    <flux:modal wire:model.self="open" class="w-full max-w-2xl" aria-label="Add employee">
        <div class="max-h-[80vh] space-y-4 overflow-y-auto">
            <div>
                <flux:heading size="lg">Add employee</flux:heading>
                <flux:text class="mt-1">Step {{ $step + 1 }} of {{ count($steps) }}</flux:text>
            </div>

            <ol class="hidden items-center sm:flex">
                @foreach ($steps as $i => $s)
                    @php
                        $done = $i < $step;
                        $current = $i === $step;
                    @endphp
                    <li wire:key="step-{{ $s['id'] }}" class="flex flex-1 items-center last:flex-none">
                        <button
                            type="button"
                            @disabled($i > $step)
                            wire:click="goTo({{ $i }})"
                            title="{{ $s['title'] }}"
                            aria-label="Step {{ $i + 1 }}: {{ $s['title'] }}"
                            @if ($current) aria-current="step" @endif
                            class="num flex h-8 w-8 shrink-0 items-center justify-center rounded-full border text-xs font-semibold transition-colors {{ $current
                                ? 'border-primary bg-primary text-primary-foreground'
                                : ($done
                                    ? 'cursor-pointer border-primary bg-primary-soft text-primary hover:bg-accent'
                                    : 'border-border bg-muted text-muted-foreground') }}"
                        >
                            @if ($done)
                                <flux:icon.check class="size-4" />
                            @else
                                {{ $i + 1 }}
                            @endif
                        </button>
                        @if ($i < count($steps) - 1)
                            <span aria-hidden="true" class="mx-1 h-0.5 flex-1 rounded-full {{ $done ? 'bg-primary' : 'bg-border' }}"></span>
                        @endif
                    </li>
                @endforeach
            </ol>

            <h3 class="border-b border-border pb-2 text-base font-semibold tracking-tight">
                {{ $stepSpec['title'] }}
            </h3>

            @foreach ($sections as $section)
                @if (count($sections) > 1)
                    <h4 class="text-sm font-semibold">{{ $section['title'] }}</h4>
                @endif

                @if ($section['id'] === 'addresses')
                    {{-- The partial opens with mt-4 (it follows a card heading on
                         the record page); -mt-4 cancels it, so this step keeps the
                         same gap under its title as every other step. --}}
                    <div wire:key="add-addresses" class="-mt-4">
                        @include('livewire.employees.partials.addresses', [
                            'fields' => $section['fields'],
                            'editing' => true,
                            'formState' => $form,
                            'fieldErrors' => $shownErrors,
                            'addressText' => ['residential' => '', 'permanent' => ''],
                            'legacyAddress' => '',
                            'groupRequired' => true,
                            'headingTag' => 'h4',
                            'referenceOptions' => $referenceOptions,
                            'sites' => $sites,
                        ])
                    </div>
                @elseif ($section['id'] === 'parents')
                    {{-- Father and Mother's maiden name sit under the h4
                         "Parents", so their own headings are one level down. --}}
                    <div wire:key="add-parents">
                        @include('livewire.employees.partials.parents', [
                            'fields' => $section['fields'],
                            'editing' => true,
                            'formState' => $form,
                            'fieldErrors' => $shownErrors,
                            'headingTag' => 'h5',
                            'referenceOptions' => $referenceOptions,
                            'sites' => $sites,
                        ])
                    </div>
                @elseif ($section['id'] === 'declarations')
                    <div wire:key="add-declarations">
                        <p class="text-sm text-muted-foreground">{{ $section['description'] }}</p>
                        @include('livewire.employees.partials.declarations', [
                            'section' => $section,
                            'editing' => true,
                            'formState' => $form,
                            'fieldErrors' => $shownErrors,
                            'headingTag' => 'h4',
                            'referenceOptions' => $referenceOptions,
                            'sites' => $sites,
                        ])
                    </div>
                @else
                    <div wire:key="add-section-{{ $section['id'] }}" class="grid gap-4 sm:grid-cols-2">
                        @foreach ($section['fields'] as $field)
                            @continue(isset($field['derived']) || ! EmployeeFields::shown($field, $form))
                            @if (isset($field['choice']))
                                {{-- Department, Unit and Position, as on the record (partials/position-fields). --}}
                                @include('livewire.employees.partials.position-fields', [
                                    'prefix' => 'add',
                                    'editing' => true,
                                    'employee' => null,
                                    'formState' => $form,
                                    'choices' => $positionChoices,
                                    'keep' => '',
                                    'error' => $shownErrors[$field['key']] ?? null,
                                    'required' => EmployeeFields::isRequired($field['key'], $form),
                                    'departmentError' => $shownErrors['department'] ?? null,
                                    'departmentRequired' => EmployeeFields::isRequired($field['key'], $form),
                                ])
                                @continue
                            @endif
                            <div wire:key="add-{{ $field['key'] }}" class="contents">
                                @include('livewire.employees.partials.field', [
                                    'field' => $field,
                                    'editing' => true,
                                    'display' => '',
                                    'formState' => $form,
                                    'referenceOptions' => $referenceOptions,
                                    'sites' => $sites,
                                    'error' => $shownErrors[$field['key']] ?? null,
                                    'required' => EmployeeFields::isRequired($field['key'], $form),
                                    'legacy' => '',
                                ])
                            </div>
                        @endforeach
                    </div>
                @endif
            @endforeach

            @foreach ($lists as $list => $listStep)
                <div wire:key="add-list-{{ $list }}">
                    @include('livewire.employees.partials.pds.wizard-rows', [
                        'list' => $list,
                        'rows' => is_array($form['rows_'.$list] ?? null) ? $form['rows_'.$list] : [],
                        'spec' => $listStep['spec'],
                        'fields' => $listStep['fields'],
                        'max' => $listStep['max'],
                        'rowErrors' => $listStep['rowErrors'],
                        'listError' => $shownErrors['rows_'.$list] ?? null,
                    ])
                </div>
            @endforeach

            <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                <div class="flex items-center gap-2">
                    <flux:button variant="outline" icon="arrow-left" :disabled="$step === 0" wire:click="goBack">
                        Back
                    </flux:button>
                    @if ($isLast)
                        <flux:button variant="primary" icon="check" wire:click="create">
                            Create employee
                        </flux:button>
                    @else
                        <flux:button variant="primary" icon:trailing="arrow-right" wire:click="goNext">
                            Next
                        </flux:button>
                    @endif
                </div>
            </div>
        </div>
    </flux:modal>
</div>
