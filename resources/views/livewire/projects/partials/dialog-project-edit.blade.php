{{-- The edit-project dialog (edit-project-dialog.tsx) — bound to Show's $editing and the edit* draft properties. Included by show.blade.php and shares that view's variables. --}}
@php
    use App\Domain\Projects\ProjectPolicy;
@endphp
{{-- edit-project-dialog.tsx --}}
<div class="space-y-4">
    <div class="space-y-4">
        @if ($runsProject && ! $canManage)
            {{-- The project's Task Leader who is not a Projects manager plans
                 the start date here. Status has its own explicit editor. --}}
            <flux:error name="projectEdit" />

            <form class="grid gap-4" wire:submit="saveProject">
                <div class="space-y-2">
                    <flux:label for="project-plan-start">Start Date</flux:label>
                    <x-kit.date-field id="project-plan-start" aria-label="Start date" wire:model="editStartDate" />
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-border pt-4">
                    <flux:button type="button" variant="ghost" wire:click="cancelProjectEdit">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Save start date</flux:button>
                </div>
            </form>
        @else
            <flux:error name="projectEdit" />

            <form class="grid gap-4 sm:grid-cols-2" wire:submit="saveProject">
                <div class="space-y-2 sm:col-span-2">
                    <flux:input label="Project name" id="project-edit-name" wire:model="editName" />
                </div>

                <div class="space-y-2 sm:col-span-2">
                    <flux:textarea label="Description" id="project-edit-description" rows="3" wire:model="editDescription" />
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
                    <flux:label>Project lead</flux:label>
                    <x-kit.select aria-label="Project lead" wire:model="editManagerId">
                        <flux:select.option value="NONE">No project lead</flux:select.option>
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
                    <flux:label for="project-edit-start">Start Date</flux:label>
                    <x-kit.date-field id="project-edit-start" aria-label="Start date" wire:model="editStartDate" />
                </div>

                <div class="space-y-2">
                    <flux:label for="project-edit-due">Due Date</flux:label>
                    <x-kit.date-field id="project-edit-due" aria-label="Due date" wire:model="editDueDate" />
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-border pt-4 sm:col-span-2">
                    <flux:button type="button" variant="ghost" wire:click="cancelProjectEdit">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Save Changes</flux:button>
                </div>
            </form>
        @endif
    </div>
</div>
