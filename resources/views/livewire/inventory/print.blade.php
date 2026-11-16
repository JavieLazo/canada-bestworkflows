{{-- Printable issue evidence reads only the receipt's frozen snapshots. --}}
@php use App\Support\HrisFormat; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Memorandum Receipt {{ $receipt->mr_number }}</title>
    <style>
        @page { size: letter portrait; margin: .5in; }
        :root { --foreground: #17212b; --muted-foreground: #56616d; --border: #cbd2d9; --card: #fff; --muted: #f1f3f5; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--muted); color: var(--foreground); font: 10pt/1.4 Arial, Helvetica, sans-serif; }
        .toolbar { margin: 20px auto; max-width: 8.5in; padding-inline: 16px; display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 10pt; }
        .toolbar button { border: 1px solid var(--border); padding: 8px 16px; border-radius: 5px; background: var(--card); color: var(--foreground); cursor: pointer; }
        .paper { width: 8.5in; min-height: 11in; margin: 0 auto 24px; padding: .5in; background: var(--card); }
        header { display: flex; justify-content: space-between; gap: 18pt; border-bottom: 2pt solid var(--foreground); padding-bottom: 14pt; }
        header > div { min-width: 0; }
        .receipt-number { flex: 0 1 35%; overflow-wrap: anywhere; }
        h1 { margin: 5pt 0; font-size: 25pt; letter-spacing: -.035em; line-height: 1.1; }
        p, dl, dd { margin: 0; }
        .brand, dt, th { font-size: 7pt; font-weight: 700; text-transform: uppercase; letter-spacing: .045em; color: var(--muted-foreground); }
        .recipient { font-size: 17pt; font-weight: 700; margin-top: 12pt; }
        .identity { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12pt; margin: 12pt 0 22pt; }
        dd { margin-top: 3pt; overflow-wrap: anywhere; }
        .hris-table { border-collapse: collapse; width: 100%; font-size: 9pt; table-layout: fixed; }
        th, td { padding: 7pt 5pt; text-align: left; border-bottom: .5pt solid var(--border); vertical-align: top; overflow-wrap: anywhere; }
        thead { display: table-header-group; }
        th { border-bottom: .75pt solid var(--foreground); }
        tr { break-inside: avoid; }
        .right { text-align: right; }
        .num { font-variant-numeric: tabular-nums; }
        .notes { margin-top: 20pt; white-space: pre-wrap; }
        .signatures { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 36pt; margin-top: 60pt; break-inside: avoid; }
        .signatures dd:first-child { border-top: .75pt solid var(--foreground); padding-top: 5pt; font-weight: 700; }
        .signatures dt { margin-top: 12pt; }
        footer { overflow-wrap: anywhere; margin-top: 35pt; padding-top: 8pt; border-top: .5pt solid var(--border); color: var(--muted-foreground); font-size: 7pt; }
        .status { margin-bottom: 16pt; padding: 8pt; border: 1pt solid var(--border); font-weight: 700; }
        @media screen and (max-width: 850px) { .paper { width: 100%; padding: 24px 16px; min-height: auto; } .identity { grid-template-columns: repeat(2, minmax(0, 1fr)); } h1 { font-size: 20pt; } .hris-table { font-size: 8pt; } }
        @media print { body { background: var(--card); } .toolbar { display: none; } .paper { width: auto; min-height: auto; margin: 0; padding: 0; } a { color: inherit; text-decoration: none; } }
    </style>
</head>
<body>
    <div class="toolbar"><span>Print the original issue record or save it as PDF.</span><button type="button" onclick="window.print()">Print MR</button></div>
    <main class="paper">
        @if ($receipt->status === 'SUPERSEDED')<p class="status">Corrected receipt — retained for history.</p>@endif
        <header><div><p class="brand">{{ $receipt->office_name }}</p><h1>Memorandum Receipt</h1><p class="recipient">{{ $receipt->employee_snapshot['name'] }}</p><p>{{ $receipt->employee_snapshot['position'] ?: '—' }}</p></div><div class="right receipt-number"><p class="brand">MR No.</p><p class="num"><strong>{{ $receipt->mr_number }}</strong></p></div></header>
        <dl class="identity"><div><dt>Department</dt><dd>{{ $receipt->employee_snapshot['department'] ?: '—' }}</dd></div><div><dt>Unit</dt><dd>{{ $receipt->employee_snapshot['unit'] ?: '—' }}</dd></div><div><dt>Date Issued</dt><dd>{{ HrisFormat::date($receipt->issued_on) }}</dd></div><div><dt>Issued By</dt><dd>{{ $receipt->issuer_snapshot['name'] }}</dd></div></dl>
        <table class="hris-table"><colgroup><col style="width: 5%"><col style="width: 15%"><col style="width: 28%"><col style="width: 20%"><col style="width: 15%"><col style="width: 17%"></colgroup><thead><tr><th>No.</th><th>Item Code</th><th>Item</th><th>Serial Number</th><th>Acquired</th><th class="right">Cost</th></tr></thead><tbody>
            @foreach ($receipt->lines as $line)<tr><td>{{ $loop->iteration }}</td><td class="num">{{ $line->item_snapshot['code'] }}</td><td>{{ $line->item_snapshot['name'] }}</td><td>{{ $line->item_snapshot['serial_no'] ?: '—' }}</td><td>{{ HrisFormat::date($line->item_snapshot['acquired_on']) }}</td><td class="right num">₱{{ number_format((float) $line->item_snapshot['cost'], 2) }}</td></tr>@endforeach
        </tbody><tfoot><tr><td colspan="5"><strong>Total · {{ $receipt->lines->count() }} items</strong></td><td class="right num"><strong>₱{{ number_format($receipt->total_cost, 2) }}</strong></td></tr></tfoot></table>
        @if ($receipt->remarks)<p class="notes">{{ $receipt->remarks }}</p>@endif
        <p class="notes">I received the items listed above. They are in my care until their return, transfer or other authorized resolution is recorded.</p>
        <div class="signatures"><dl><dd>{{ $receipt->employee_snapshot['name'] }}</dd><dd>{{ $receipt->employee_snapshot['position'] ?: '—' }}</dd><dt>Received by · Signature Over Printed Name</dt><dt>Date Signed __________________</dt></dl><dl><dd>{{ $receipt->issuer_snapshot['name'] }}</dd><dd>{{ $receipt->issuer_snapshot['position'] ?: '—' }}</dd><dt>Issued by · Signature Over Printed Name</dt><dt>Date Signed __________________</dt></dl></div>
        <footer>Memorandum Receipt {{ $receipt->mr_number }} · Original issue details</footer>
    </main>
</body>
</html>
