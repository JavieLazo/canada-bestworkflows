@php
    use App\Modules\WorkManagement\Infrastructure\ProjectMembership;
@endphp
@if ($canManage)
    <flux:modal wire:model.self="memberEditorOpen" class="w-full max-w-md">
        <form wire:submit="saveMember" class="space-y-5">
            <flux:heading size="lg" level="2">{{ $editingMemberUserId === null ? 'Add project member' : 'Project access' }}</flux:heading>
            @if ($editingMemberUserId === null)
                <x-kit.select label="Person" aria-label="Project member" wire:model="memberUserId" searchable>
                    <flux:select.option value="">Choose a person</flux:select.option>
                    @foreach ($memberAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            @else
                <p class="text-sm font-medium">{{ $accountName($editingMemberUserId) }}</p>
            @endif
            @if ($editingMemberUserId !== null)<flux:error name="memberUserId" />
            @endif
            <x-kit.select label="Project access" aria-label="Project access role" wire:model.live="memberRole">
                @foreach (ProjectMembership::ROLES as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </x-kit.select>
            <p class="text-xs text-muted-foreground">{{ match ($memberRole) { 'EDITOR' => 'Plan tasks, groups and dates. Reviews and project administration stay with managers and leads.', 'VIEWER' => 'Read all project tasks. Individual responses stay private.', default => 'See project details and assigned work. My Tasks users keep using My Tasks.' } }}</p>
            <flux:input label="Project position (optional)" wire:model="memberPosition" maxlength="80" placeholder="e.g. Designer" />
            @if ($client !== null)
                <p class="text-xs text-muted-foreground">Client access is required separately. This role applies to this project only.</p>
            @endif
            @if ($editingMemberUserId !== null)
                <p class="text-xs text-muted-foreground">Existing assignments, lead and client-owner access also apply.</p>
            @endif
            <div class="flex flex-wrap items-center justify-end gap-2">
                @if ($editingMemberUserId !== null)<flux:button type="button" size="sm" variant="danger" class="mr-auto" wire:click="removeMember">Remove project role</flux:button>
                @endif
                <flux:button type="button" size="sm" variant="ghost" wire:click="$set('memberEditorOpen', false)">Cancel</flux:button>
                <flux:button type="submit" size="sm" variant="primary">{{ $editingMemberUserId === null ? 'Add member' : 'Save access' }}</flux:button>
            </div>
        </form>
    </flux:modal>
@endif
