{{--
    Employee record page — port of employee-detail.tsx:224-397.

    Left column: portrait + Personal Details + Emergency Contact + the delete
    button. Right column: Employee Details, Statutory & Bank, Payroll
    Settings, then the Documents card. One section edits at a time.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;
@endphp
<div class="space-y-4">
    @if ($employee === null)
        <div class="hris-card p-10 text-center">
            <h1 class="text-lg font-semibold">Employee not found</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                This record may have been removed.
                <a href="{{ route('employees.index') }}" class="text-primary hover:underline">Back to Employee Records</a>
            </p>
        </div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <a
                href="{{ route('employees.index') }}"
                class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
                <flux:icon.arrow-left class="size-4" />
                Employee Records
            </a>
        </div>

        <div class="record-print grid items-start gap-4 xl:grid-cols-[22rem_minmax(0,1fr)]">
            {{-- ────────────────────────────────────────────── left column --}}
            <div class="hris-card divide-y divide-border overflow-hidden">
                <div class="flex flex-col items-center gap-3 p-6">
                    <span class="flex h-28 w-28 items-center justify-center overflow-hidden rounded-md bg-muted">
                        @if ($photoUrl)
                            <img src="{{ $photoUrl }}" alt="{{ EmployeeFields::employeeName($employee) }}" class="h-full w-full object-cover" />
                        @else
                            <span class="num text-2xl font-semibold">{{ EmployeeFields::initials($employee) }}</span>
                        @endif
                    </span>
                    <input
                        type="file"
                        id="employee-photo-input"
                        accept="image/*"
                        class="hidden"
                        wire:model="photo"
                    />
                    <flux:button
                        size="sm"
                        variant="outline"
                        icon="arrow-up-tray"
                        class="print:hidden"
                        x-data
                        x-on:click="document.getElementById('employee-photo-input').click()"
                    >
                        Upload
                    </flux:button>
                </div>

                @foreach ($sections as $section)
                    @continue($section['column'] !== 'left')
                    <div wire:key="section-{{ $section['id'] }}">
                        @include('livewire.employees.partials.section', [
                            'section' => $section,
                            'employee' => $employee,
                            'editing' => $editingSection === $section['id'],
                            'columns' => 1,
                            'display' => $display,
                            'formState' => $form,
                            'formErrors' => $formErrors,
                            'referenceOptions' => $referenceOptions,
                            'sites' => $sites,
                        ])
                    </div>
                @endforeach

                <section class="p-5 print:hidden">
                    <flux:button
                        variant="danger"
                        icon="trash"
                        class="w-full"
                        wire:click="$set('deleteConfirmOpen', true)"
                    >
                        Delete employee
                    </flux:button>
                </section>
            </div>

            {{-- ───────────────────────────────────────────── right column --}}
            <div class="space-y-4">
                @foreach ($sections as $section)
                    @continue($section['column'] !== 'right')
                    <div wire:key="section-{{ $section['id'] }}" class="hris-card overflow-hidden">
                        @include('livewire.employees.partials.section', [
                            'section' => $section,
                            'employee' => $employee,
                            'editing' => $editingSection === $section['id'],
                            'columns' => 2,
                            'display' => $display,
                            'formState' => $form,
                            'formErrors' => $formErrors,
                            'referenceOptions' => $referenceOptions,
                            'sites' => $sites,
                        ])
                    </div>
                @endforeach

                <livewire:employees.documents :employee-id="$employee->id" wire:key="documents-{{ $employee->id }}" />
            </div>
        </div>

        <flux:modal wire:model.self="deleteConfirmOpen" class="w-full max-w-md" aria-label="Delete employee">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Delete {{ EmployeeFields::employeeName($employee) }}?</flux:heading>
                    <flux:text class="mt-2">
                        Only an inactive test record with no linked account, shift, attendance, leave,
                        document, task or payroll history can be deleted. Records with history must remain
                        inactive so reports and audit evidence stay complete.
                        @if ($accountsCount > 0)
                            {{ $accountsCount }} linked {{ $accountsCount === 1 ? 'account' : 'accounts' }} must be unlinked first.
                        @else
                            No sign-in accounts are linked to this employee.
                        @endif
                    </flux:text>
                </div>
                <div class="flex items-center justify-end gap-2">
                    <flux:button variant="outline" wire:click="$set('deleteConfirmOpen', false)">
                        Cancel
                    </flux:button>
                    <flux:button variant="danger" icon="trash" wire:click="deleteEmployee">
                        Delete employee
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
