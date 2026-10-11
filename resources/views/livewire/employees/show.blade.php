{{-- One official employee record; My Profile edits create requests for management approval. --}}
@php
    use App\Livewire\Employees\EmployeeFields;
@endphp
<div
    class="record-page space-y-4"
    x-data
    x-on:record-print.window="$el.dataset.print = $event.detail.sheet; $nextTick(() => window.print())"
    x-on:afterprint.window="delete $el.dataset.print"
>
    @if ($employee === null)
        <div class="hris-card p-10 text-center">
            <h1 class="text-lg font-semibold">Employee not found</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                This record may have been removed.
                <a href="{{ route('employees.index') }}" class="text-primary hover:underline">Back to Employee Records</a>
            </p>
        </div>
    @else
        @if (! $own)
        <div class="flex flex-wrap items-center justify-between gap-3">
            @if (! $own)
            <a
                href="{{ route('employees.index') }}"
                class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
                <flux:icon.arrow-left class="size-4" />
                Employee Records
            </a>
            @endif
            <div class="ml-auto flex flex-wrap items-center gap-2">
                @if (! $own)
                <flux:button
                    variant="outline"
                    size="sm"
                    icon="clock"
                    :href="route('employees.history', ['employee' => $employee->id])"
                >
                    Employment History
                </flux:button>
                @endif
                @if ($canPrint)
                <flux:dropdown position="bottom" align="end">
                    <flux:button variant="outline" size="sm" icon="printer">Print</flux:button>
                    <flux:menu>
                        <flux:menu.item x-on:click="$dispatch('record-print', { sheet: '201' })">201 File</flux:menu.item>
                        <flux:menu.item wire:click="printPds">Personal Data Sheet (Excel)</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
                @endif
            </div>
        </div>
        @endif

        <div class="print:hidden">
            {{-- The same tab strip Leave Management draws (leaves/index): a
                 muted role=tablist of role=tab buttons; $set fires
                 updatedTab(), which whitelists the value. Its height follows
                 its content and it wraps, so the ten tabs take a second row
                 at narrower widths instead of being clipped by the page's
                 overflow-x clip. Below sm the tabs become a Section select
                 bound to the same property. --}}
            <div class="hidden sm:block">
                <div class="inline-flex h-auto flex-wrap items-center justify-start rounded-lg bg-muted p-1 text-muted-foreground" role="tablist" aria-label="Record sections">
                    @foreach ($tabs as $key => $label)
                        <button
                            type="button"
                            role="tab"
                            aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                            wire:click="$set('tab', '{{ $key }}')"
                            class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === $key ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="relative w-full sm:hidden">
                <x-kit.select wire:model.live="tab" aria-label="Section">
                    @foreach ($tabs as $key => $label)
                        <flux:select.option value="{{ $key }}" :selected="$key === $tab">{{ $label }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            </div>
        </div>

        @if ($tab === 'profile')
            <div class="grid items-start gap-4 xl:grid-cols-[22rem_minmax(0,1fr)]">
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
                        @if ($canEdit)
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
                            x-data
                            x-on:click="document.getElementById('employee-photo-input').click()"
                        >
                            {{ $requiresApproval ? 'Change photo' : 'Upload' }}
                        </flux:button>
                        @if ($requiresApproval && $photoUrl)
                            <flux:button size="sm" variant="ghost" wire:click="stagePortraitRemoval">Remove photo</flux:button>
                        @endif
                        @endif
                    </div>

                    @foreach ($profileLeft as $sectionId)
                        @php($section = EmployeeFields::section($sectionId))
                        <div wire:key="section-{{ $section['id'] }}">
                            @include('livewire.employees.partials.section', [
                                'section' => $section,
                                'employee' => $employee,
                                'editing' => $editingSection === $section['id'],
                                'columns' => $section['columns'] ?? 1,
                                'display' => $display,
                                'formState' => $form,
                                'formErrors' => $formErrors,
                                'referenceOptions' => $referenceOptions,
                                'sites' => $sites,
                                'legacy' => $legacy,
                                'addressText' => $addressText,
                                'canEdit' => $canEdit,
                            ])
                        </div>
                    @endforeach

                    @if ($canManage)
                    <section class="p-5">
                        <flux:button
                            variant="danger"
                            icon="trash"
                            class="w-full"
                            wire:click="$set('deleteConfirmOpen', true)"
                        >
                            Delete employee
                        </flux:button>
                    </section>
                    @endif
                </div>

                {{-- ───────────────────────────────────────────── right column --}}
                <div class="min-w-0 space-y-4">
                    @foreach ($profileRight as $sectionId)
                        @php($section = EmployeeFields::section($sectionId))
                        <div wire:key="section-{{ $section['id'] }}" class="hris-card overflow-hidden">
                            @include('livewire.employees.partials.section', [
                                'section' => $section,
                                'employee' => $employee,
                                'editing' => $editingSection === $section['id'],
                                'columns' => $section['columns'] ?? 2,
                                'display' => $display,
                                'formState' => $form,
                                'formErrors' => $formErrors,
                                'referenceOptions' => $referenceOptions,
                                'sites' => $sites,
                                'legacy' => $legacy,
                                'addressText' => $addressText,
                                'canEdit' => $canEdit,
                            ])
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif ($tab === 'inventory')
            <livewire:inventory.employee-items :own="$own" :employee-id="$employee->id" :key="'employee-inventory-'.$employee->id" />
        @elseif ($tab === 'family')
            {{-- One expression: a block-form php directive after the inline
                 ones above would swallow the markup between them. --}}
            @php($sectionArgs = ['employee' => $employee, 'display' => $display, 'formState' => $form, 'formErrors' => $formErrors, 'referenceOptions' => $referenceOptions, 'sites' => $sites, 'legacy' => $legacy, 'addressText' => $addressText, 'canEdit' => $canEdit])
            <div class="grid items-start gap-4 xl:grid-cols-2">
                <div wire:key="section-spouse" class="hris-card overflow-hidden">
                    @include('livewire.employees.partials.section', $sectionArgs + [
                        'section' => EmployeeFields::section('spouse'),
                        'editing' => $editingSection === 'spouse',
                        'columns' => 2,
                    ])
                </div>

                <div class="min-w-0 space-y-4">
                    <div wire:key="section-parents" class="hris-card overflow-hidden">
                        @include('livewire.employees.partials.section', $sectionArgs + [
                            'section' => EmployeeFields::section('parents'),
                            'editing' => $editingSection === 'parents',
                            'columns' => 2,
                        ])
                    </div>

                    <section wire:key="section-children" class="hris-card overflow-hidden">
                        @include('livewire.employees.partials.children', $sectionArgs + [
                            'section' => EmployeeFields::section('children'),
                            'editing' => $editingSection === 'children',
                        ])
                    </section>
                </div>
            </div>
        @elseif (in_array($tab, $listTabs, true))
            <livewire:employees.pds-list :own="$own" :employee-id="$employee->id" :list="$tab" wire:key="pds-list-{{ $tab }}-{{ $employee->id }}" />
        @elseif ($tab === 'other')
            <div class="grid items-start gap-4 xl:grid-cols-3">
                @foreach (['skills', 'distinctions', 'memberships'] as $sectionId)
                    <div wire:key="section-{{ $sectionId }}" class="hris-card overflow-hidden">
                        @include('livewire.employees.partials.section', [
                            'section' => EmployeeFields::section($sectionId),
                            'employee' => $employee,
                            'editing' => $editingSection === $sectionId,
                            'columns' => 1,
                            'display' => $display,
                            'formState' => $form,
                            'formErrors' => $formErrors,
                            'referenceOptions' => $referenceOptions,
                            'sites' => $sites,
                            'legacy' => $legacy,
                            'addressText' => $addressText,
                            'canEdit' => $canEdit,
                        ])
                    </div>
                @endforeach
            </div>
        @elseif ($tab === 'declarations')
            <div class="space-y-4">
                <div wire:key="section-declarations" class="hris-card overflow-hidden">
                    @include('livewire.employees.partials.section', [
                        'section' => EmployeeFields::section('declarations'),
                        'employee' => $employee,
                        'editing' => $editingSection === 'declarations',
                        'columns' => 2,
                        'display' => $display,
                        'formState' => $form,
                        'formErrors' => $formErrors,
                        'referenceOptions' => $referenceOptions,
                        'sites' => $sites,
                        'legacy' => $legacy,
                        'addressText' => $addressText,
                        'canEdit' => $canEdit,
                    ])
                </div>
            </div>
        @elseif ($tab === 'documents' && ! $requiresApproval)
            <livewire:employees.documents :own="$own" :employee-id="$employee->id" wire:key="documents-{{ $employee->id }}" />
        @endif

        @if ($canManage)
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

        @if ($requiresApproval)
            <flux:modal wire:model.self="portraitOpen" class="w-full max-w-lg">
                <form wire:submit="submitPortrait" class="space-y-4" x-init="$el.closest('dialog')?.setAttribute('aria-labelledby', 'portrait-request-heading')">
                    <flux:heading size="lg" id="portrait-request-heading">{{ $portraitRemoved ? 'Request photo removal' : 'Request photo change' }}</flux:heading>
                    <p class="text-sm text-muted-foreground">Your current photo remains in use until management approves this request.</p>
                    @if ($photo !== null)
                        <p class="break-words text-sm">Selected: {{ $photo->getClientOriginalName() }}</p>
                    @endif
                    <div class="flex flex-wrap justify-end gap-2">
                        <flux:modal.close><flux:button variant="outline">Cancel</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary">Submit for approval</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif

        @if ($canPrint)
        @include('livewire.employees.partials.sheet', [
            'employee' => $employee,
            'siteName' => $siteName,
            'photoUrl' => $photoUrl,
        ])
        @endif
    @endif
</div>
