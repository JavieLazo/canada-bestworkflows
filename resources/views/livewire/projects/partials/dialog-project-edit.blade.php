{{-- The edit-project dialog (edit-project-dialog.tsx) — bound to Show's $editing and the edit* draft properties. Included by show.blade.php and shares that view's variables. --}}
@php
    use App\Domain\Projects\ProjectPolicy;
    use App\Livewire\Projects\ProjectsData;
@endphp
{{-- edit-project-dialog.tsx --}}
<flux:modal wire:model.self="editing" class="max-h-[90vh] w-full max-w-lg overflow-y-auto">
    <div class="space-y-4">
        @if ($runsProject && ! $canManage)
            {{-- The project's Task Leader who is not a Projects manager plans
                 the start date and nothing else here (ScheduleProject); the
                 status is the header select's. --}}
            <div>
                <flux:heading size="lg">Plan project start</flux:heading>
                <flux:text class="mt-1">
                    As Task Leader of {{ $project->code }} you can move its start date. Ask a Projects manager
                    to change anything else.
                </flux:text>
            </div>

            <form class="grid gap-4" wire:submit="saveProject">
                <div class="space-y-2">
                    <flux:label for="project-plan-start">Start date</flux:label>
                    <x-kit.date-field id="project-plan-start" aria-label="Start date" wire:model="editStartDate" />
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
                    <flux:button type="submit" variant="primary">Save start date</flux:button>
                </div>
            </form>
        @else
            <div>
                <flux:heading size="lg">Edit project</flux:heading>
                <flux:text class="mt-1">
                    Update the project brief, task leader, due date, or current status for {{ $project->code }}.
                </flux:text>
            </div>

            <form class="grid gap-4 sm:grid-cols-2" wire:submit="saveProject">
                <div class="space-y-2 sm:col-span-2">
                    <flux:label for="project-edit-name">Project name</flux:label>
                    <flux:input id="project-edit-name" wire:model="editName" />
                </div>

                <div class="space-y-2 sm:col-span-2">
                    <flux:label for="project-edit-description">Description</flux:label>
                    <flux:textarea id="project-edit-description" rows="3" wire:model="editDescription" />
                </div>

                <div class="space-y-2">
                    <flux:label>Site</flux:label>
                    <x-kit.select aria-label="Project site" wire:model="editSiteId">
                        <flux:select.option value="NONE">No site</flux:select.option>
                        {{-- An inactive site stays offered while the project names it. --}}
                        @foreach ($sites as $siteRow)
                            @continue(! $siteRow->is_active && $project->site_id !== $siteRow->id)
                            <flux:select.option value="{{ $siteRow->id }}">{{ $siteRow->name }}{{ $siteRow->is_active ? '' : ' (inactive)' }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                </div>

                <div class="space-y-2">
                    <flux:label>Task Leader</flux:label>
                    <x-kit.select aria-label="Project task leader" wire:model="editManagerId">
                        <flux:select.option value="NONE">No task leader</flux:select.option>
                        @foreach ($accounts as $account)
                            {{-- Accounts whose role can open Project Management,
                                 plus the leader already named, so an older
                                 choice still shows (UpdateProject re-checks a
                                 change of leader, not the one kept). --}}
                            @if (in_array((int) $account->id, $leaderIds, true) || (int) $account->id === (int) $project->manager_id)
                                <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                            @endif
                        @endforeach
                    </x-kit.select>
                </div>

                <div class="space-y-2">
                    <flux:label>Status</flux:label>
                    <x-kit.select aria-label="Project status" wire:model="editStatus">
                        @foreach (ProjectPolicy::STATUSES as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                </div>

                <div class="space-y-2">
                    <flux:label for="project-edit-start">Start date</flux:label>
                    <x-kit.date-field id="project-edit-start" aria-label="Start date" wire:model="editStartDate" />
                </div>

                <div class="space-y-2">
                    <flux:label for="project-edit-due">Due date</flux:label>
                    <x-kit.date-field id="project-edit-due" aria-label="Due date" wire:model="editDueDate" />
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-border pt-4 sm:col-span-2">
                    <p class="num text-xs text-muted-foreground">
                        Created {{ ProjectsData::fmtDateManila($project->created_at) }}
                    </p>
                    <flux:button type="submit" variant="primary">Save changes</flux:button>
                </div>
            </form>
        @endif
    </div>
</flux:modal>
