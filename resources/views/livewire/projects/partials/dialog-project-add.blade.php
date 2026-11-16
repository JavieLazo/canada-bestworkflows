@php
    use App\Domain\Projects\ProjectPolicy;
@endphp
<flux:modal wire:model.self="adding" class="w-full max-w-lg">
    <form class="space-y-5" wire:submit="createProject">
        <flux:heading size="lg">New project</flux:heading>
        <flux:input required wire:model="draftName" label="Project name" placeholder="Name your project" maxlength="200" autofocus />
        <x-kit.select wire:model.live="draftClientId" label="Client" aria-label="Project client">
            @if ($canCreateInternal)
                <flux:select.option value="NONE">Internal project</flux:select.option>
            @else
                <flux:select.option value="NONE" disabled>Choose a client</flux:select.option>
            @endif
            @foreach ($creatableClients as $clientRow)
                <flux:select.option value="{{ $clientRow->id }}">{{ $clientRow->name }}</flux:select.option>
            @endforeach
        </x-kit.select>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-2"><flux:label>Start date</flux:label><x-kit.date-field required wire:model="draftStartDate" aria-label="Start date" /></div>
            <div class="space-y-2"><flux:label>Due date</flux:label><x-kit.date-field wire:model="draftDueDate" aria-label="Due date" /></div>
        </div>
        <details class="rounded-lg border border-border px-4 py-3">
            <summary class="w-fit cursor-pointer text-sm font-medium">Project details</summary>
            <div class="space-y-4 pt-4">
                <flux:textarea wire:model="draftDescription" label="Description" rows="2" placeholder="What are we delivering?" maxlength="4000" />
                <x-kit.select label="Project lead" aria-label="Project lead" wire:model="draftManagerId" searchable>
                    <flux:select.option value="NONE">No project lead</flux:select.option>
                    @foreach ($accounts as $account)
                        @if (in_array((int) $account->id, $leaderIds, true))
                            <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                        @endif
                    @endforeach
                </x-kit.select>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-kit.select label="Status" aria-label="Status" wire:model="draftStatus">
                        @foreach (ProjectPolicy::STATUSES as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
@endforeach
                    </x-kit.select>
                    <x-kit.select label="Site" aria-label="Site" wire:model="draftSiteId">
                        <flux:select.option value="NONE">No site</flux:select.option>
                        @foreach ($sites as $siteRow)
                            @if ($siteRow->is_active)<flux:select.option value="{{ $siteRow->id }}">{{ $siteRow->name }}</flux:select.option>
@endif
                        @endforeach
                    </x-kit.select>
                </div>
            </div>
        </details>
        <div class="flex justify-end gap-2 border-t border-border pt-4">
            <flux:button type="button" variant="ghost" wire:click="$set('adding', false)">Cancel</flux:button>
            <flux:button type="submit" variant="primary">Create project</flux:button>
        </div>
    </form>
</flux:modal>
