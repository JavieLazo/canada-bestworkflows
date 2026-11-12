{{--
    A leave type's RULE fields, in cards: the waiting period and the credit
    grant on acquiring the leave, the monthly credit accrual, and the type
    a request draws on once the available balance runs out. A switch heads
    each optional card and its fields show only while it is on. Bound to
    the page's `ruleForm`; drawn by the rule dialog and by the Add leave
    type dialog. `$ruleToken` is the type being edited ('' for a new one),
    left out of the fallback choices. `$ruleCredits` says whether that type
    is paid from earned leave credits: only such a type accrues, is granted
    credits on acquiring it, or falls back on another such type
    (`$creditTokens`); the others have a waiting period alone.

    TWO COLUMNS FROM lg, so the wide dialogs read without scrolling: the
    waiting period on the left, the accrual over the fallback on the right;
    one column below lg, in that same order. A type with the waiting period
    alone has it across both columns.
--}}
@php
    use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
    use App\Modules\Timekeeping\Domain\Credits\LeaveTypeRule;

    $waits = (bool) ($ruleForm['waiting'] ?? false);
    $earns = (bool) ($ruleForm['earns'] ?? false);
@endphp
<div class="grid gap-4 lg:grid-cols-2 lg:items-start" data-rule-columns>
<div @class(['rounded-lg border border-border', 'lg:col-span-2' => ! $ruleCredits]) data-rule-waiting>
    <div class="p-4">
        <flux:switch wire:model.live="ruleForm.waiting" align="left" label="Waiting period" description="Acquired only after a waiting period. Off: the employee has this leave from the first day." />
    </div>
    @if ($waits)
        <div class="space-y-3 border-t border-border p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input label="Months after the date hired" type="number" min="1" max="600" step="1" wire:model="ruleForm.waiting_months" />
                @if ($ruleCredits)
                    <x-kit.select label="Credit grant on the day it is acquired" wire:model="ruleForm.on_acquire">
                        @foreach (LeaveTypeRule::ON_ACQUIRE_LABELS as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach
                    </x-kit.select>
                @endif
            </div>
            @if ($ruleCredits)
                <p class="-mt-1 text-xs text-muted-foreground">A credit grant gives the monthly accrual × the months waited, on that day. Before the leave is acquired it cannot be filed, accrues no credits and has no credit deductions.</p>
            @else
                <p class="-mt-1 text-xs text-muted-foreground">Before the leave is acquired it cannot be filed.</p>
            @endif
            <flux:checkbox wire:model="ruleForm.waiting_extends" label="Unpaid days extend the wait" description="Each whole day absent or on approved unpaid leave pushes the day back by one. Half days add up. Late and undertime never count." />
        </div>
    @endif
</div>

@if ($ruleCredits)
    <div class="space-y-4">
        <div class="rounded-lg border border-border" data-rule-earning>
            <div class="p-4">
                <flux:switch wire:model.live="ruleForm.earns" align="left" label="Monthly credit accrual" description="Credits are earned every month once the leave is acquired." />
            </div>
            @if ($earns)
                <div class="space-y-3 border-t border-border p-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input label="Monthly accrual" type="number" min="0" step="0.0001" wire:model="ruleForm.amount" />
                        <flux:input label="Maximum balance" type="number" min="0" step="0.0001" wire:model="ruleForm.cap" placeholder="No maximum" />
                        <x-kit.select label="Accrual method" wire:model.live="ruleForm.method">
                            @foreach (LeavePolicy::METHOD_LABELS as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach
                        </x-kit.select>
                        <x-kit.select label="Partial month accrual" wire:model="ruleForm.partial">
                            @foreach (LeavePolicy::PARTIAL_LABELS as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach
                        </x-kit.select>
                    </div>
                    @if (($ruleForm['method'] ?? '') === 'TABLE')
                        {{-- The column names are drawn once; each row's inputs carry them, with the row number, as their names. --}}
                        <div class="space-y-2 rounded-md bg-muted/40 p-3" data-leave-rule-table>
                            <div>
                                <p class="text-sm font-medium">Lookup table</p>
                                <p class="text-xs text-muted-foreground">Each row: up to this many unpaid days in the month (approved unpaid leave and absences), this many credits are earned. Past the last row, none.</p>
                            </div>
                            @if (($ruleForm['table'] ?? []) !== [])
                                <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-center gap-2">
                                    <span class="self-end text-xs font-medium text-muted-foreground" aria-hidden="true">Up to (unpaid days)</span>
                                    <span class="self-end text-xs font-medium text-muted-foreground" aria-hidden="true">Credits earned</span>
                                    <span aria-hidden="true"></span>
                                    @foreach ($ruleForm['table'] as $index => $row)
                                        <div class="contents" wire:key="rule-row-{{ $index }}">
                                            <flux:input type="number" min="0" step="0.0001" wire:model="ruleForm.table.{{ $index }}.up_to" aria-label="Up to (unpaid days), row {{ $index + 1 }}" />
                                            <flux:input type="number" min="0" step="0.0001" wire:model="ruleForm.table.{{ $index }}.credits" aria-label="Credits earned, row {{ $index + 1 }}" />
                                            <flux:button variant="ghost" icon="trash" wire:click="removeRuleRow({{ $index }})" aria-label="Remove lookup row {{ $index + 1 }}" />
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            <flux:button size="sm" variant="ghost" icon="plus" wire:click="addRuleRow">Add row</flux:button>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <div class="space-y-2 rounded-lg border border-border p-4" data-rule-fallback>
            <x-kit.select label="When the available balance runs out, a request draws on" wire:model="ruleForm.fallback">
                <flux:select.option value="">Nothing: the rest is unpaid leave</flux:select.option>
                @foreach ($types as $type)
                    @if ($type->token !== $ruleToken && in_array($type->token, $creditTokens, true))<flux:select.option value="{{ $type->token }}">{{ $type->name }}</flux:select.option>@endif
                @endforeach
            </x-kit.select>
            <p class="text-xs text-muted-foreground">Days neither balance covers are unpaid leave days.</p>
        </div>
    </div>
@endif
</div>
