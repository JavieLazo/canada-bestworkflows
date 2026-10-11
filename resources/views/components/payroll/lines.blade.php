{{-- A row group from an issued document; descriptive subtotals never create a new calculation. --}}
@props(['title', 'lines', 'totalLabel' => null, 'total' => null, 'currency' => true])

<section>
    <div class="hris-payslip-section-title"><h3>{{ $title }}</h3>@if ($currency)<span>PHP</span>@endif</div>
    <div class="hris-table-wrap">
        <table class="hris-table hris-payslip-table">
            <caption class="sr-only">{{ $title }}{{ $currency ? ' in Philippine pesos' : '' }}</caption>
            <tbody>
                @foreach ($lines as $line)
                    <tr @class(['hris-payslip-basic' => ($line['style'] ?? '') === 'basic', 'hris-payslip-detail' => ($line['style'] ?? '') === 'detail'])>
                        <th scope="row">
                            {{ $line['label'] }}
                            @if (($line['quantity'] ?? '') !== '')<span class="hris-payslip-quantity">({{ $line['quantity'] }})</span>@endif
                            @if (($line['note'] ?? '') !== '')<span class="hris-payslip-quantity block">{{ $line['note'] }}</span>@endif
                        </th>
                        <td @class(['num', 'text-muted-foreground line-through' => $line['excluded'] ?? false])>{{ $line['value'] }}</td>
                    </tr>
                @endforeach
            </tbody>
            @if ($totalLabel !== null)
                <tfoot><tr><th scope="row">{{ $totalLabel }}</th><td class="num">{{ $total }}</td></tr></tfoot>
            @endif
        </table>
    </div>
</section>
