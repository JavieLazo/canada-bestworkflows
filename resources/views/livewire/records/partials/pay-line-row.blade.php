{{--
    One of the employee's recurring lines in Allowances & incentives ($withTax:
    Line, Amount, Runs, Tax) or Loans & other deductions (Line, Per payroll,
    Balance, Runs), then who set it (their own), its status in a column
    of its own and its menu. A line that has ended offers no End: ending
    only brings the last date earlier.
--}}
@php
    use App\Livewire\Records\Support\Hris;
    use App\Livewire\Records\Support\RecurringLineText;
    use App\Modules\Payroll\Application\PayrollPeriodText;
    use App\Modules\Payroll\Application\Queries\RecurringLineRecords;
@endphp
<tr wire:key="line-{{ $line->id }}">
    <td>
        <p class="font-medium">{{ $line->type_name }}</p>
        <p class="text-xs text-muted-foreground">{{ $line->note ?: ($withTax ? 'Allowance' : 'Deduction') }}</p>
    </td>
    <td class="num whitespace-nowrap text-right">
        {{ Hris::money($line->amount) }}
        <span class="block text-xs text-muted-foreground">{{ RecurringLineText::FREQUENCIES[$line->frequency] ?? $line->frequency }}</span>
    </td>
    @unless ($withTax)
        <td class="num whitespace-nowrap text-right">
            @if ($line->left === null)
                <span class="text-muted-foreground">No balance</span>
            @else
                {{ Hris::money($line->left) }}
            @endif
            @if ($line->total !== null)
                <span class="block text-xs text-muted-foreground">of {{ Hris::money($line->total) }}</span>
            @endif
        </td>
    @endunless
    <td class="text-sm">
        <span class="whitespace-nowrap">From <span class="num">{{ PayrollPeriodText::day($line->starts_on->format('Y-m-d')) }}</span></span>
        <span class="block text-xs text-muted-foreground">
            @if (isset($payoffs[$line->id]))
                Paid off on the payroll from <span class="num">{{ PayrollPeriodText::day($payoffs[$line->id]['last']) }}</span>
            @elseif ($line->ends_on !== null)
                Last period from <span class="num">{{ PayrollPeriodText::day($line->ends_on->format('Y-m-d')) }}</span>
            @else
                No end date
            @endif
        </span>
    </td>
    @if ($withTax)
        <td>{{ $incentiveTaxes[$line->type_id] ?? '—' }}</td>
    @endif
    <td>@include('livewire.records.partials.pay-source', ['from' => 'own', 'status' => $status])</td>
    <td><span class="{{ RecurringLineText::STATUS_PILLS[$line->status] }}">{{ RecurringLineText::STATUSES[$line->status] }}</span></td>
    <td class="text-right">
        <x-kit.row-actions :label="$line->type_name">
            <flux:menu.item icon="pencil" wire:click="editLine('{{ $line->id }}')">Edit</flux:menu.item>
            @if (! $withTax && $line->total !== null && ($entriesReady ?? false))
                <flux:menu.item icon="banknotes" wire:click="openEntry('{{ $line->id }}')">Record Payment or Adjustment</flux:menu.item>
            @endif
            @if ($line->status !== RecurringLineRecords::ENDED)
                <flux:menu.item icon="stop-circle" wire:click="startEnd('{{ $line->id }}')">End</flux:menu.item>
            @endif
            <flux:menu.item
                icon="trash"
                :variant="$line->posted ? 'default' : 'danger'"
                :disabled="$line->posted"
                wire:click="confirmDeleteLine('{{ $line->id }}')"
            >
                {{ $line->posted ? 'Delete · already paid' : 'Delete' }}
            </flux:menu.item>
        </x-kit.row-actions>
    </td>
</tr>
