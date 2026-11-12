{{-- One side of a slip: its lines under their group bands, then the side's total. --}}
@props(['title', 'groups', 'totalLabel', 'total'])

<div class="hris-table-wrap">
    <table class="hris-table hris-payslip-table">
        <caption class="sr-only">{{ $title }} in Philippine pesos</caption>
        <thead><tr><th scope="col">{{ $title }}</th><th scope="col" class="num">Amount</th></tr></thead>
        <tbody>
            @foreach ($groups as $group)
                @if ($group['title'] !== '')
                    <tr class="hris-payslip-group"><th colspan="2" scope="colgroup">{{ $group['title'] }}</th></tr>
                @endif
                @foreach ($group['lines'] as $line)
                    <tr @class(['hris-payslip-basic' => ($line['style'] ?? '') === 'basic', 'hris-payslip-detail' => ($line['style'] ?? '') === 'detail', 'hris-payslip-zero' => $line['zero']])>
                        <th scope="row">
                            {{ $line['label'] }}
                            @if (($line['quantity'] ?? '') !== '')<span class="hris-payslip-quantity">({{ $line['quantity'] }})</span>@endif
                            @if (($line['note'] ?? '') !== '')<span class="hris-payslip-quantity block">{{ $line['note'] }}</span>@endif
                        </th>
                        <td @class(['num', 'line-through' => $line['excluded'] ?? false])>{{ $line['shown'] }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
        <tfoot><tr><th scope="row">{{ $totalLabel }}</th><td class="num">{{ $total }}</td></tr></tfoot>
    </table>
</div>
