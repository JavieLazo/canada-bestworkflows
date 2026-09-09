{{--
    Payroll Formula Settings — the port of
    src/app/(protected)/records/payroll-formulas/formulas-page.tsx. Same sample
    derivation (monthly ÷ days ÷ hours), same formula cards with live results,
    same drafts-until-saved flow with the "Unsaved changes" note, same copy.
--}}
@php
    use App\Livewire\Records\Support\Hris;
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Payroll Formula Settings</h1>
        <p class="text-sm text-muted-foreground">
            The numbers each computation runs on. Enter a sample monthly rate and the days worked
            to follow it through to a daily rate and see what each formula produces.
        </p>
    </div>

    <div class="hris-card p-5">
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="space-y-2">
                <flux:label for="sample-monthly">Monthly rate</flux:label>
                <x-kit.money-input
                    id="sample-monthly"
                    class="num"
                    wire:model.live.debounce.250ms="monthlyRate"
                    aria-label="Monthly rate"
                />
            </div>
            <div class="space-y-2">
                <flux:label for="sample-days">Days worked in the month</flux:label>
                <flux:input
                    id="sample-days"
                    type="number"
                    min="1"
                    max="31"
                    step="1"
                    class:input="num"
                    wire:model.live.debounce.250ms="daysWorked"
                />
            </div>
            <div class="space-y-2">
                <flux:label for="sample-hours">Hours in a working day</flux:label>
                <flux:input
                    id="sample-hours"
                    type="number"
                    min="1"
                    max="24"
                    step="0.5"
                    class:input="num"
                    wire:model.live.debounce.250ms="hoursInADay"
                />
            </div>
        </div>

        <dl class="mt-4 flex flex-wrap items-baseline gap-x-3 gap-y-2 rounded-md border border-border bg-muted px-4 py-3 text-sm">
            <dt class="text-muted-foreground">Daily rate</dt>
            <dd class="num font-semibold">
                {{ Hris::peso($monthly) }} &divide; {{ $days ?: 0 }} = {{ Hris::peso(Hris::round2($pay)) }}
            </dd>
            <span aria-hidden="true" class="text-muted-foreground">&middot;</span>
            <dt class="text-muted-foreground">Hourly rate</dt>
            <dd class="num font-semibold">
                @if ($hoursPerDay > 0)
                    {{ Hris::peso(Hris::round2($pay)) }} &divide; {{ $hoursPerDay }} h = {{ Hris::peso(Hris::round2($hourly)) }}
                @else
                    &mdash;
                @endif
            </dd>
        </dl>

        <p class="mt-3 text-xs text-muted-foreground">
            A payroll run does not read these three &mdash; it takes the monthly rate from the
            employee record, the scheduled days and the hours in their working day from the shift
            assigned in Shifting Settings. A six-hour shift makes each hour worth more than an
            eight-hour one on the same monthly rate.
        </p>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        @foreach ($rows as $row)
            <section wire:key="formula-{{ $row->getKey() }}" class="hris-card p-5">
                <h2 class="text-lg font-bold">
                    {{ $row->name }} = <span class="num text-primary">{{ $results[$row->key] ?? 0 }}</span>
                </h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    <span class="font-semibold uppercase tracking-wide">Formula:</span>
                    <span class="italic">{{ $row->expression }}</span>
                </p>

                <div class="mt-4 space-y-4">
                    @foreach ($row->params as $param)
                        <div wire:key="param-{{ $row->key }}-{{ $param['key'] }}" class="space-y-2">
                            <flux:label>{{ $param['label'] }}</flux:label>
                            <flux:input
                                type="number"
                                step="0.01"
                                class:input="num"
                                wire:model.live.debounce.250ms="drafts.{{ $row->key }}.{{ $param['key'] }}"
                            />
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    <div class="flex items-center justify-end gap-3">
        @if ($dirty)
            <p class="text-xs text-muted-foreground">Unsaved changes</p>
        @endif
        {{-- lucide's Save (floppy) has no heroicon twin; arrow-down-tray is the
             closest Flux icon. --}}
        <flux:button
            variant="primary"
            icon="arrow-down-tray"
            wire:click="save"
            :disabled="! $dirty"
        >
            Save changes
        </flux:button>
    </div>

    <p class="text-xs text-muted-foreground">
        A payroll run reads these numbers: overtime is paid at the OT percentage, a worked rest
        day at the Rest Day percentage, and the night differential at the NSD percentage for each
        hour between 22:00 and 06:00 the shift covers. What turns a daily rate into an hourly one
        is the employee's own shift, not a number here, and No. of Days per Month belongs to SIL
        rather than to the daily rate &mdash; their scheduled days do that.
    </p>
</div>
