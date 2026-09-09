{{--
    Add-employee wizard — port of add-employee-dialog.tsx:206-329.

    Numbered dots joined by a rail (labels stay out of it), the section
    heading naming the step, a 2-column field grid, and Back / Next /
    Create employee in the footer.
--}}
<div>
    <flux:modal wire:model.self="open" class="w-full max-w-2xl" aria-label="Add employee">
        <div class="max-h-[80vh] space-y-4 overflow-y-auto">
            <div>
                <flux:heading size="lg">Add employee</flux:heading>
                <flux:text class="mt-1">Step {{ $step + 1 }} of {{ count($sections) }}</flux:text>
            </div>

            <ol class="flex items-center">
                @foreach ($sections as $i => $s)
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
                        @if ($i < count($sections) - 1)
                            <span aria-hidden="true" class="mx-2 h-0.5 flex-1 rounded-full {{ $done ? 'bg-primary' : 'bg-border' }}"></span>
                        @endif
                    </li>
                @endforeach
            </ol>

            <h3 class="border-b border-border pb-2 text-base font-semibold tracking-tight">
                {{ $section['title'] }}
            </h3>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($section['fields'] as $field)
                    @continue(isset($field['derived']))
                    <div wire:key="add-{{ $field['key'] }}" class="contents">
                        @include('livewire.employees.partials.field', [
                            'field' => $field,
                            'editing' => true,
                            'display' => '',
                            'formState' => $form,
                            'referenceOptions' => $referenceOptions,
                            'sites' => $sites,
                            'error' => $shownErrors[$field['key']] ?? null,
                            'required' => in_array($field['key'], \App\Livewire\Employees\EmployeeFields::REQUIRED, true),
                        ])
                    </div>
                @endforeach
            </div>

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
