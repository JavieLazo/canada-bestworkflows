{{--
    The leave policy fields, shared by the preset dialog (Leave Credits
    settings) and the policy dialog (Leave Management): the posting day,
    what counts as a day without pay, an earning section per leave type
    that earns, and the charge rules. `$form` names the component property
    the fields bind to; `$addRow` / `$removeRow` the actions for the lookup
    rows; `$earningTypes` the types that earn; `$types` every type (for the
    charge ticks). Reads the bound draft through $this->{$form}.
--}}
@php
    use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;

    $draft = $this->{$form};
    $cell = 'num h-8 w-full min-w-[5rem] rounded-md border border-border bg-background px-2 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring';
@endphp
<div class="space-y-5" data-policy-fields>
    <div class="grid gap-3 sm:grid-cols-2">
        <x-kit.select label="Earning posts on" wire:model="{{ $form }}.posting_day">
            <flux:select.option value="{{ LeavePolicy::MONTH_END }}">The last day of the month</flux:select.option>
            @for ($d = 1; $d <= 28; $d++)<flux:select.option value="{{ $d }}">Day {{ $d }}</flux:select.option>@endfor
        </x-kit.select>
        <div class="space-y-2">
            <flux:label>Days without pay are</flux:label>
            <flux:checkbox wire:model="{{ $form }}.unpaid_leave" :label="LeavePolicy::UNPAID_LABELS['UNPAID_LEAVE']" />
            <flux:checkbox wire:model="{{ $form }}.unpaid_absence" :label="LeavePolicy::UNPAID_LABELS['ABSENCE']" />
        </div>
        <flux:input label="Cash-out limit per year (blank for no limit)" type="number" min="0" step="0.5" wire:model="{{ $form }}.cash_out_max" description="The most days of credits the employee may turn into pay in a calendar year, over the types Leave Credits allows." data-cash-out-max />
    </div>

    <div class="space-y-3">
        <p class="text-sm font-semibold">Earning</p>
        @if ($earningTypes->isEmpty())
            <p class="text-sm text-muted-foreground">No leave type earns credits yet. Turn "Earns credits" on for a type under Leave Credits › Leave types first.</p>
        @endif
        @foreach ($earningTypes as $type)
            @php $token = $type->token; $rule = $draft['earning'][$token] ?? null; @endphp
            @continue($rule === null)
            <div class="space-y-3 rounded-md border border-border p-4" wire:key="earning-{{ $token }}" data-earning="{{ $token }}">
                <flux:checkbox wire:model.live="{{ $form }}.earning.{{ $token }}.enabled" :label="$type->name.' earns credits under this policy'" />
                @if ($rule['enabled'] ?? false)
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input label="Monthly amount" type="number" min="0" step="0.0001" wire:model="{{ $form }}.earning.{{ $token }}.amount" />
                        <x-kit.select label="How a month is earned" wire:model.live="{{ $form }}.earning.{{ $token }}.method">
                            @foreach (LeavePolicy::METHOD_LABELS as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>@endforeach
                        </x-kit.select>
                        <x-kit.select label="A month not served whole" wire:model="{{ $form }}.earning.{{ $token }}.partial">
                            @foreach (LeavePolicy::PARTIAL_LABELS as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>@endforeach
                        </x-kit.select>
                        <flux:input label="Balance cap (blank for none)" type="number" min="0" step="0.0001" wire:model="{{ $form }}.earning.{{ $token }}.cap" />
                    </div>
                    <p class="text-xs text-muted-foreground">Credits never lapse: at year end the whole balance carries into the new year.</p>
                    @if (($rule['method'] ?? '') === 'TABLE')
                        <div class="space-y-2" data-lookup="{{ $token }}">
                            <p class="text-xs text-muted-foreground">Days without pay in the month, up to and including the first column, earn the second. Past the last row the month earns nothing.</p>
                            @foreach ($rule['rows'] ?? [] as $index => $row)
                                <div class="flex flex-wrap items-center gap-2" wire:key="lookup-{{ $token }}-{{ $index }}">
                                    <span class="text-sm text-muted-foreground">Up to</span>
                                    <input type="text" inputmode="decimal" class="{{ $cell }} max-w-[6rem]" wire:model="{{ $form }}.earning.{{ $token }}.rows.{{ $index }}.up_to" aria-label="{{ $type->name }} row {{ $index + 1 }} days without pay" />
                                    <span class="text-sm text-muted-foreground">days without pay earn</span>
                                    <input type="text" inputmode="decimal" class="{{ $cell }} max-w-[6rem]" wire:model="{{ $form }}.earning.{{ $token }}.rows.{{ $index }}.credits" aria-label="{{ $type->name }} row {{ $index + 1 }} credits" />
                                    <flux:button variant="ghost" size="sm" square icon="x-mark" wire:click="{{ $removeRow }}('{{ $token }}', {{ $index }})" aria-label="Remove row {{ $index + 1 }}" />
                                </div>
                            @endforeach
                            <flux:button variant="outline" size="sm" icon="plus" wire:click="{{ $addRow }}('{{ $token }}')">Add row</flux:button>
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    <div class="space-y-3 rounded-md border border-border p-4" data-charges>
        <flux:checkbox wire:model.live="{{ $form }}.charges_enabled" label="Charge lateness, undertime and absences to leave credits on each payroll; what credits cannot cover comes out of pay" />
        @if ($draft['charges_enabled'] ?? false)
            <x-kit.select label="Late and undertime minutes" wire:model="{{ $form }}.charges_combine">
                @foreach (LeavePolicy::COMBINE_LABELS as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>@endforeach
            </x-kit.select>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-2">
                    <flux:label>Lateness and undertime are charged to, in order</flux:label>
                    @forelse ($draft['charge_late'] ?? [] as $token => $on)
                        <flux:checkbox wire:model="{{ $form }}.charge_late.{{ $token }}" :label="$types->first(fn ($t) => $t->token === $token)?->name ?? $token" wire:key="late-{{ $token }}" />
                    @empty
                        <p class="text-xs text-muted-foreground">No leave type may be charged for lateness yet (Leave Credits › Leave types).</p>
                    @endforelse
                </div>
                <div class="space-y-2">
                    <flux:label>Absences are charged to, in order</flux:label>
                    @forelse ($draft['charge_absence'] ?? [] as $token => $on)
                        <flux:checkbox wire:model="{{ $form }}.charge_absence.{{ $token }}" :label="$types->first(fn ($t) => $t->token === $token)?->name ?? $token" wire:key="absence-{{ $token }}" />
                    @empty
                        <p class="text-xs text-muted-foreground">No leave type may be charged for absences yet (Leave Credits › Leave types).</p>
                    @endforelse
                </div>
            </div>
        @endif
    </div>
</div>
