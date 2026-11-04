{{--
    Leave policies — a tab of Leave Management with no counterpart in the
    source. One row per employee the actor may see, with the leave policy in
    force for them today (how credits are earned each month, what is charged
    to credits) and the drafts awaiting approval; behind all_data + LEAVES a
    row menu opens the versions dialog and the policy dialog. The policy
    fields are the shared partial the preset dialog uses, bound to
    `policyForm`. See App\Livewire\Leaves\LeavePolicies.
--}}
@php
    use App\Platform\Files\PortraitUrl;
    use App\Support\EmployeeIdentity;
    use App\Support\EmployeeName;
    use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;

    $columns = $canManage ? 4 : 3;
@endphp
<div class="space-y-4" data-leave-policies>
    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input aria-label="Search employees" placeholder="Search ID, name or position" class="w-full min-w-[12rem] max-w-xs flex-1" wire:model.live.debounce.250ms="search" />
            <p class="text-xs text-muted-foreground">How each employee's leave credits are earned and charged. Earning posts by itself once a month has ended.</p>
            @if ($canManage && $ready)
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <flux:button variant="outline" icon="arrow-path" wire:click="postEarningsNow" wire:loading.attr="disabled" wire:target="postEarningsNow">Post earnings now</flux:button>
                </div>
            @endif
        </header>

        @unless ($ready)
            <p class="px-5 py-6 text-sm text-muted-foreground">Leave policies become available once this installation's upgrade finishes.</p>
        @else
            <div class="hris-table-wrap hris-table-scroll">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <x-kit.sort-header field="employee" label="Employee" />
                            <th>Policy in force</th>
                            <th class="whitespace-nowrap">Drafts</th>
                            @if ($canManage)
                                <th class="w-10"><span class="sr-only">Actions</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $employee)
                            @php
                                /** @var \App\Modules\Workforce\Contracts\DirectoryEmployee $employee */
                                $policy = $inForce[$employee->id] ?? null;
                                $photo = $employee->photo_path ? PortraitUrl::for($employee->photo_path) : null;
                                $draftCount = $drafts[$employee->id] ?? 0;
                            @endphp
                            <tr wire:key="policy-{{ $employee->id }}" @class(['opacity-60' => ! $employee->is_active])>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                                            @if ($photo)
                                                <img src="{{ $photo }}" alt="{{ EmployeeName::display($employee) }}" class="h-full w-full object-cover" />
                                            @else
                                                <span class="num text-xs font-semibold">{{ EmployeeName::initials($employee) }}</span>
                                            @endif
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate font-medium">{{ EmployeeName::display($employee) }}</p>
                                            <p class="num truncate text-xs text-muted-foreground">{{ EmployeeIdentity::meta(EmployeeIdentity::besideName($employee), $employee->position) ?: '—' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-sm">
                                    @if ($policy === null)
                                        <span class="text-muted-foreground">No policy — earns nothing, attendance charged to pay</span>
                                    @else
                                        <span class="block max-w-xl">{{ $policy->summary }}</span>
                                        <span class="num text-xs text-muted-foreground">since {{ $policy->effective_from->format('d M Y') }}</span>
                                    @endif
                                </td>
                                <td class="num whitespace-nowrap">
                                    @if ($draftCount > 0)
                                        <span class="pill pill-caution">{{ $draftCount }} awaiting approval</span>
                                    @else
                                        <span class="text-muted-foreground">—</span>
                                    @endif
                                </td>
                                @if ($canManage)
                                    <td class="text-right">
                                        <x-kit.row-actions :label="EmployeeName::display($employee)">
                                            <flux:menu.item icon="clock" wire:click="openVersions('{{ $employee->id }}')">Versions…</flux:menu.item>
                                            <flux:menu.item icon="plus" wire:click="openPolicy('{{ $employee->id }}')">New version…</flux:menu.item>
                                        </x-kit.row-actions>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr wire:key="empty"><x-kit.empty-state :colspan="$columns" title="No employee matches those filters." /></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-kit.pagination :paginator="$rows" noun="employees" />
        @endunless
    </section>

    {{-- Versions: every dated version of one employee, and the earning postings made. --}}
    <flux:modal wire:model.self="versionsOpen" class="w-full max-w-3xl" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'policy-versions-heading')">
        <div class="space-y-4" data-policy-versions>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <flux:heading size="lg" id="policy-versions-heading">Leave policy{{ $dialogEmployee !== null ? ' · '.EmployeeName::display($dialogEmployee) : '' }}</flux:heading>
                @if ($canManage && $dialogEmployee !== null)
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="openPolicy('{{ $dialogEmployee->id }}')">New version</flux:button>
                @endif
            </div>
            <div class="hris-table-wrap">
                <table class="hris-table">
                    <thead><tr><th>Takes effect</th><th>Rules</th><th>Status</th><th class="w-10"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($versions as $version)
                            <tr wire:key="version-{{ $version->id }}" data-version-status="{{ $version->status }}">
                                <td class="num whitespace-nowrap">{{ $version->effective_from->format('d M Y') }}</td>
                                <td class="text-sm">
                                    <span class="block">{{ $version->summary }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ $version->reason }} · prepared by {{ $version->prepared_by_name ?? 'an unnamed account' }}{{ $version->approved_by_name !== null ? ', approved by '.$version->approved_by_name : '' }}</span>
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($version->status === 'DRAFT')
                                        <span class="pill pill-caution">Draft</span>
                                    @elseif ($version->in_force)
                                        <span class="pill pill-success">In force</span>
                                    @else
                                        <span class="pill pill-muted">Approved</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if ($canManage && $version->status === 'DRAFT')
                                        <x-kit.row-actions :label="'Version from '.$version->effective_from->format('d M Y')">
                                            @if ((string) $version->prepared_by !== (string) $userId)
                                                <flux:menu.item icon="check" wire:click="askApprove('{{ $version->id }}')">Approve…</flux:menu.item>
                                            @endif
                                            <flux:menu.item icon="pencil-square" wire:click="openPolicy('{{ $version->employee_id }}', '{{ $version->id }}')">Edit draft</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="askDiscard('{{ $version->id }}')">Discard…</flux:menu.item>
                                        </x-kit.row-actions>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr wire:key="versions-empty"><x-kit.empty-state :colspan="4" title="No policy yet." description="Without one this employee earns no credits and their attendance is charged to pay." /></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($postings->isNotEmpty())
                <div>
                    <p class="text-sm font-semibold">Earning posted</p>
                    <div class="hris-table-wrap mt-2">
                        <table class="hris-table">
                            <thead><tr><th>Period</th><th>Leave type</th><th class="text-right">Credits</th><th>How</th></tr></thead>
                            <tbody>
                                @foreach ($postings as $posting)
                                    <tr wire:key="posting-{{ $posting->id }}">
                                        <td class="num whitespace-nowrap">{{ str_ends_with($posting->period, '-YE') ? substr($posting->period, 0, 4).' year end' : $posting->period }}</td>
                                        <td>{{ $posting->type_name }}</td>
                                        <td class="num text-right">{{ LeaveQuantity::format($posting->days) }}</td>
                                        <td class="text-xs text-muted-foreground">{{ $posting->basis }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
            @if ($charges->isNotEmpty())
                <div data-policy-charges>
                    <p class="text-sm font-semibold">Attendance charged to credits</p>
                    <div class="hris-table-wrap mt-2">
                        <table class="hris-table">
                            <thead><tr><th>Date</th><th>What</th><th class="text-right">Days</th><th>Charged to</th><th class="text-right">Left for pay</th></tr></thead>
                            <tbody>
                                @foreach ($charges as $charge)
                                    <tr wire:key="charge-{{ $charge->id }}">
                                        <td class="num whitespace-nowrap">{{ $charge->date->format('d M Y') }}</td>
                                        <td class="text-sm">{{ $charge->kind === 'LATE' ? 'Lateness and undertime · '.$charge->minutes.' min' : 'Absence' }}</td>
                                        <td class="num text-right">{{ LeaveQuantity::format($charge->days) }}</td>
                                        <td class="text-sm">{{ $charge->covered_text }}</td>
                                        <td class="num text-right">{{ $charge->uncovered_days > 0 ? LeaveQuantity::format($charge->uncovered_days) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
            <div class="flex justify-end">
                <flux:button variant="ghost" wire:click="$set('versionsOpen', false)">Close</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Approve or discard a draft only from here: approving posts months on
         the next run and is never undone, so the dialog says what follows and
         its one button acts on the STAGED version (confirmVersion()). --}}
    <flux:modal wire:model.self="versionConfirmOpen" class="w-full max-w-md" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'policy-confirm-heading')">
        <div class="space-y-4" data-policy-confirm="{{ $confirmAction }}">
            <flux:heading size="lg" id="policy-confirm-heading">{{ $confirmAction === 'approve' ? 'Approve this version?' : 'Discard this draft?' }}</flux:heading>
            @if ($confirmAction === 'approve')
                <p class="text-sm text-muted-foreground">
                    The version from <span class="num">{{ $confirmFrom }}</span> takes effect once approved and cannot be changed or discarded afterwards; a later version supersedes it.
                </p>
                <p class="text-sm" data-policy-confirm-months="{{ count($confirmMonths) }}">
                    @if ($confirmMonths === [])
                        No month is due yet: the earning posts from the first month whose posting day passes.
                    @elseif (count($confirmMonths) === 1)
                        The next run posts the earning for <span class="num">{{ $confirmMonths[0] }}</span> at once.
                    @else
                        The next run posts <span class="num">{{ count($confirmMonths) }}</span> months at once, <span class="num">{{ $confirmMonths[0] }}</span> to <span class="num">{{ $confirmMonths[count($confirmMonths) - 1] }}</span>, each from today's balances.
                    @endif
                </p>
            @else
                <p class="text-sm text-muted-foreground">The draft from <span class="num">{{ $confirmFrom }}</span> is removed. Nothing posted changes.</p>
            @endif
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('versionConfirmOpen', false)">Cancel</flux:button>
                <flux:button :variant="$confirmAction === 'approve' ? 'primary' : 'danger'" wire:click="confirmVersion">{{ $confirmAction === 'approve' ? 'Approve' : 'Discard' }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- The policy dialog: a new dated version, or a draft edited in place. --}}
    <flux:modal wire:model.self="policyOpen" class="w-full max-w-3xl" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'policy-dialog-heading')">
        <div class="space-y-4" data-policy-dialog>
            <flux:heading size="lg" id="policy-dialog-heading">{{ $editingVersionId === null ? 'New leave policy version' : 'Edit draft' }}{{ $dialogEmployee !== null ? ' · '.EmployeeName::display($dialogEmployee) : '' }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-2">
                    <flux:label>Takes effect on</flux:label>
                    <x-kit.date-field wire:model="policyFrom" aria-label="Takes effect on" />
                </div>
                @if ($presets->isNotEmpty())
                    <x-kit.select label="Start from a preset" wire:model.live="policyPreset">
                        <flux:select.option value="">Keep the fields as they are</flux:select.option>
                        @foreach ($presets as $preset)
                            <flux:select.option value="{{ $preset->id }}">{{ $preset->name }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                @endif
            </div>
            @include('livewire.leaves.partials.policy-fields', ['form' => 'policyForm', 'addRow' => 'addPolicyRow', 'removeRow' => 'removePolicyRow', 'earningTypes' => $earningTypes, 'types' => $types])
            <flux:textarea label="Why this version" wire:model="policyReason" rows="2" maxlength="500" placeholder="e.g. Regularized on 1 Nov" />
            @if ($policyError)
                <p role="alert" class="text-sm text-danger">{{ $policyError }}</p>
            @endif
            <p class="text-xs text-muted-foreground">Saved as a draft. Someone other than you approves it; from its date the earning and the charges follow it, and it never changes again.</p>
            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('policyOpen', false)">Cancel</flux:button>
                <flux:button variant="primary" icon="check" wire:click="savePolicy" wire:loading.attr="disabled" wire:target="savePolicy">Save draft</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
