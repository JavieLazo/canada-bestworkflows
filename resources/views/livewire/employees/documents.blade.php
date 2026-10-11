{{--
    Documents card — port of employee-documents.tsx:117-429.

    Category filtering and CSV share the scoped document query. The storage
    footer and add-document dialog cover every document, regardless of the filter.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;
@endphp
<section class="hris-card overflow-hidden">
    @if ($employee !== null)
        <header class="flex flex-wrap items-center justify-between gap-4 border-b border-border px-5 py-4">
            <div>
                <h2 class="text-base font-bold">Documents</h2>
                <p class="text-sm text-muted-foreground">
                    Contracts, IDs and certificates on file for {{ EmployeeFields::employeeName($employee) }}.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-kit.export-button :href="route('employees.documents.export', ['employee' => $employeeId, 'category' => $category])" />
                @if ($canEdit)
                <flux:button variant="primary" icon="plus" wire:click="$set('adding', true)">
                    Add document
                </flux:button>
                @endif
            </div>
        </header>

        <div class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-3">
            <x-kit.filter-select wire:model.live="category" label="Filter by category" all-value="" class="w-full sm:w-64">
                <flux:select.option value="" :selected="$category === ''">All categories</flux:select.option>
                @foreach ($filterCategories as $filterCategory)
                    <flux:select.option value="{{ $filterCategory }}" :selected="$category === $filterCategory">{{ $filterCategory }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>
        </div>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Document</th>
                        <th>Category</th>
                        <th>Expiration</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @if ($total === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="5" :title="$category === '' ? 'No documents on file yet.' : 'No documents match this category.'" />
                        </tr>
                    @endif
                    @foreach ($list as $doc)
                        <tr wire:key="{{ $doc->id }}">
                            <td class="num whitespace-nowrap">{{ $doc->document_no ?? '—' }}</td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <flux:icon.document-text class="size-4 shrink-0 text-muted-foreground" />
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">{{ $doc->name }}</p>
                                        <p class="num truncate text-xs text-muted-foreground">
                                            {{ $doc->file_name }} · {{ EmployeeFields::humanSize($doc->size) }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap text-muted-foreground">
                                {{ EmployeeFields::categoryLabel($doc->category) }}
                            </td>
                            <td class="num whitespace-nowrap">
                                @if ($doc->expires_on !== null)
                                    <span class="{{ $doc->expires_on->format('Y-m-d') < $today ? 'text-danger' : '' }}">
                                        {{ \App\Livewire\Kit\DateText::toDisplay($doc->expires_on->format('Y-m-d')) }}
                                    </span>
                                @else
                                    <span class="text-muted-foreground">—</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="arrow-down-tray"
                                        href="{{ route('employees.documents.download', $doc->id) }}"
                                    >
                                        Download
                                    </flux:button>
                                    @if ($canEdit)
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        square
                                        aria-label="Delete {{ $doc->name }}"
                                        wire:click="removeDocument('{{ $doc->id }}')"
                                    >
                                        <flux:icon.trash class="size-4 text-danger" />
                                    </flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$list" noun="documents" />

        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3 text-xs text-muted-foreground">
            <span>
                Storage used <span class="num">{{ EmployeeFields::humanSize($usedBytes) }}</span>
            </span>
            <span>Max {{ EmployeeFields::humanSize($maxFileBytes) }} per file.</span>
        </footer>

        <flux:modal wire:model.self="adding" class="w-full max-w-lg" aria-label="Add document">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Add document</flux:heading>
                    <flux:text class="mt-1">
                        Attach a file to {{ EmployeeFields::employeeName($employee) }} and describe what it is.
                    </flux:text>
                </div>

                <form class="grid gap-4 sm:grid-cols-2" wire:submit="submit">
                    <div class="space-y-2 sm:col-span-2">
                        <flux:label>File</flux:label>
                        <input type="file" id="employee-document-input" class="hidden" wire:model="upload" />
                        <div class="flex items-center gap-3">
                            <flux:button
                                type="button"
                                variant="outline"
                                icon="paper-clip"
                                x-data
                                x-on:click="document.getElementById('employee-document-input').click()"
                            >
                                {{ $upload ? 'Change file' : 'Choose file' }}
                            </flux:button>
                            <p class="min-w-0 truncate text-sm text-muted-foreground">
                                @if ($upload)
                                    {{ $upload->getClientOriginalName() }} · {{ EmployeeFields::humanSize((int) $upload->getSize()) }}
                                @else
                                    No file chosen
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <flux:label>ID</flux:label>
                        <flux:input class:input="num" placeholder="04-3188554-8" wire:model="form.document_no" />
                        <p class="text-xs text-muted-foreground">Number printed on the document.</p>
                    </div>

                    <div class="space-y-2">
                        <flux:label>Category</flux:label>
                        <x-kit.select wire:model.live="form.category" aria-label="Category">
                            <flux:select.option value="" disabled hidden>Select a category</flux:select.option>
                            @if ($categories === [])
                                <flux:select.option value="" disabled>No document types yet — add them in Records Management.</flux:select.option>
                            @endif
                            @foreach ($categories as $category)
                                <flux:select.option value="{{ $category }}">{{ $category }}</flux:select.option>
                            @endforeach
                        </x-kit.select>
                        <p class="text-xs text-muted-foreground">
                            Managed in Records Management → Document Types.
                        </p>
                    </div>

                    <div class="space-y-2">
                        <flux:label>Document</flux:label>
                        <flux:input required placeholder="Driver's licence" wire:model="form.name" />
                    </div>

                    <div class="space-y-2">
                        <flux:label>Expiration</flux:label>
                        <x-kit.date-field wire:model="form.expires_on" aria-label="Expiration" />
                    </div>

                    <div class="sm:col-span-2">
                        <flux:button type="submit" variant="primary" icon="plus">
                            Add document
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    @endif
</section>
