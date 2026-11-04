@php
    /*
     * Invoice PDF (dompdf, A4). Everything shown comes from the issued snapshot (seller, customer, lines, totals) plus
     * the live payment state passed in. Lines, subtotal and the generation date are prepared by InvoiceService::pdf;
     * when the view is rendered without them (tests), they are worked out here the same way.
     */
    $lines ??= \App\Services\Sales\InvoiceService::pdfLines($invoice);
    $subtotal ??= \App\Support\Finance\Money::sum(array_column($lines, 'amount'));
    $generatedOn ??= \Carbon\CarbonImmutable::now();
    $symbols = ['NGN' => '₦'];
    $cur = fn (string $a) => isset($symbols[$invoice->currency]) ? $symbols[$invoice->currency].$money($a) : $invoice->currency.' '.$money($a);
    $day = fn ($d) => $d ? $d->format('j M Y') : null;
    $statusLabel = ['unpaid' => 'unpaid', 'partially_paid' => 'partially paid', 'paid' => 'paid', 'void' => 'void'][$status] ?? str_replace('_', ' ', $status);
    $words = preg_split('/\s+/u', trim((string) $invoice->seller_name), -1, PREG_SPLIT_NO_EMPTY);
    $initials = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2)))) ?: 'F';
    $customer = $invoice->customer_name ?? 'Walk-in customer';
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $invoice->reference }}</title>
<style>
    /* Palette: forest green (brand), charcoal (text), muted grey (labels), light neutrals (sections) */
    @page { margin: 20mm 16mm 22mm 16mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; line-height: 1.45; color: #1f2622; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; padding: 0; }
    .muted { color: #5f6a64; }
    .label { font-size: 7.5pt; font-weight: bold; letter-spacing: 1.2px; text-transform: uppercase; color: #2f6b3f; }
    .num { text-align: right; white-space: nowrap; }

    /* A thin brand bar at the top of every page; the footer on every page */
    .bar { position: fixed; top: -20mm; left: -16mm; right: -16mm; height: 5px; background: #2f6b3f; }
    .footer { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 7.5pt; color: #5f6a64; border-top: 1px solid #e3e8e4; padding-top: 6px; }
    .footer td { vertical-align: middle; }

    /* Header: the brand on the left, the invoice summary on the right */
    .head td { vertical-align: middle; }
    .mark { width: 42px; height: 30px; padding-top: 12px; border-radius: 21px; background: #2f6b3f; color: #ffffff; font-size: 13pt; font-weight: bold; text-align: center; line-height: 1; }
    .farm { font-size: 16pt; font-weight: bold; color: #1f2622; line-height: 1.2; }
    .farm-sub { font-size: 8.5pt; color: #5f6a64; }
    .title { font-size: 26pt; font-weight: bold; letter-spacing: 3px; color: #2f6b3f; line-height: 1; }
    .number { font-size: 12pt; font-weight: bold; color: #1f2622; margin-top: 6px; }
    .pill { display: inline-block; margin-top: 7px; padding: 3px 10px; border-radius: 10px; font-size: 7.5pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
    .pill-unpaid { background: #fbf1d9; color: #7a5300; }
    .pill-partially_paid { background: #fbf1d9; color: #7a5300; }
    .pill-paid { background: #e3f0e6; color: #24563a; }
    .pill-void { background: #eceeed; color: #4a524e; }
    .rule { height: 1px; background: #e3e8e4; margin: 18px 0 20px 0; }

    /* Bill to / Invoice details */
    .card { background: #f5f7f5; border-left: 3px solid #2f6b3f; padding: 12px 14px; }
    .who { font-size: 11.5pt; font-weight: bold; color: #1f2622; margin: 5px 0 3px 0; }
    .facts td { padding: 2px 0; font-size: 9pt; }
    .facts td.k { color: #5f6a64; width: 44%; }
    .facts td.v { color: #1f2622; font-weight: bold; text-align: right; }

    /* Items */
    .items { margin-top: 22px; table-layout: fixed; }
    .items thead { display: table-header-group; }
    .items th { background: #e8f1ea; color: #24563a; font-size: 7.5pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; text-align: left; padding: 8px 10px; }
    .items th.num { text-align: right; }
    .items td { padding: 9px 10px; border-bottom: 1px solid #e3e8e4; font-size: 9.5pt; }
    .items tr { page-break-inside: avoid; }
    .items .desc { word-wrap: break-word; color: #1f2622; }
    .items .none { color: #9aa39e; }

    /* Summary */
    .summary-wrap { margin-top: 16px; page-break-inside: avoid; }
    .summary td { padding: 5px 12px; font-size: 9.5pt; }
    .summary .k { color: #5f6a64; }
    .summary .total td { border-top: 1.5px solid #1f2622; padding-top: 8px; font-size: 12pt; font-weight: bold; color: #1f2622; }
    .summary .due td { background: #e8f1ea; color: #24563a; font-size: 12.5pt; font-weight: bold; padding: 9px 12px; }

    /* Payments, notes */
    .section { margin-top: 24px; page-break-inside: avoid; }
    .section h3 { margin: 0 0 6px 0; }
    .pay td { padding: 5px 0; border-bottom: 1px solid #eef1ee; font-size: 9pt; }
    .note { font-size: 9pt; color: #1f2622; white-space: pre-line; }
    .void-note { margin-top: 14px; padding: 9px 12px; background: #f4f5f4; border-left: 3px solid #8b9590; font-size: 9pt; color: #3a423e; }
</style>
</head>
<body>
<div class="bar"></div>
<div class="footer">
    <table>
        <tr>
            <td>{{ $invoice->seller_name }} &middot; Invoice {{ $invoice->reference }}</td>
            {{-- "Page X of Y" is stamped beside this by InvoiceService::pdf once the pages are known --}}
            <td class="num" style="padding-right: 52pt;">Generated {{ $day($generatedOn) }}</td>
        </tr>
    </table>
</div>

<table class="head">
    <tr>
        <td style="width: 54px;"><div class="mark">{{ $initials }}</div></td>
        <td style="padding-left: 12px;">
            <div class="farm">{{ $invoice->seller_name }}</div>
            <div class="farm-sub">Issued {{ $day($invoice->issue_date) }}@if ($invoice->due_date) &middot; Due {{ $day($invoice->due_date) }}@endif</div>
        </td>
        <td class="num" style="width: 42%;">
            <div class="title">INVOICE</div>
            <div class="number">{{ $invoice->reference }}</div>
            <span class="pill pill-{{ $status }}">{{ $statusLabel }}</span>
        </td>
    </tr>
</table>
<div class="rule"></div>

<table>
    <tr>
        <td style="width: 48%;">
            <div class="card">
                <div class="label">Bill to</div>
                <div class="who">{{ $customer }}</div>
                @if ($invoice->customer_address) <div>{{ $invoice->customer_address }}</div> @endif
                @if ($invoice->customer_phone) <div>{{ $invoice->customer_phone }}</div> @endif
                @if ($invoice->customer_email) <div>{{ $invoice->customer_email }}</div> @endif
            </div>
        </td>
        <td style="width: 4%;"></td>
        <td style="width: 48%;">
            <div class="card">
                <div class="label">Invoice details</div>
                <table class="facts" style="margin-top: 5px;">
                    <tr><td class="k">Invoice number</td><td class="v">{{ $invoice->reference }}</td></tr>
                    <tr><td class="k">Issue date</td><td class="v">{{ $day($invoice->issue_date) }}</td></tr>
                    @if ($invoice->due_date) <tr><td class="k">Due date</td><td class="v">{{ $day($invoice->due_date) }}</td></tr> @endif
                    @if ($invoice->sale) <tr><td class="k">Sale reference</td><td class="v">{{ $invoice->sale->reference }}</td></tr> @endif
                    <tr><td class="k">Payment status</td><td class="v" style="text-transform: capitalize;">{{ $statusLabel }}</td></tr>
                </table>
            </div>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th style="width: 46%;">Description</th>
            <th style="width: 16%;">Quantity</th>
            <th class="num" style="width: 19%;">Unit price</th>
            <th class="num" style="width: 19%;">Line total</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($lines as $line)
        <tr>
            <td class="desc">{{ $line['description'] }}</td>
            <td>@if ($line['quantity']){{ $line['quantity'] }}@else<span class="none">&mdash;</span>@endif</td>
            <td class="num">@if ($line['unit_price']){{ $cur($line['unit_price']) }}@else<span class="none">&mdash;</span>@endif</td>
            <td class="num">{{ $cur($line['amount']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="summary-wrap">
    <table>
        <tr>
            <td style="width: 52%;"></td>
            <td style="width: 48%;">
                <table class="summary">
                    <tr><td class="k">Subtotal</td><td class="num">{{ $cur($subtotal) }}</td></tr>
                    <tr class="total"><td>Total</td><td class="num">{{ $cur((string) $invoice->total_amount) }}</td></tr>
                    <tr><td class="k">Amount paid</td><td class="num">{{ $cur($paid) }}</td></tr>
                    <tr class="due"><td>Balance due</td><td class="num">{{ $cur($outstanding) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
</div>

@if ($status === 'void')
    <div class="void-note">This invoice is void{{ $invoice->voided_at ? ' since '.$day($invoice->voided_at) : '' }}.{{ $invoice->void_reason ? ' Reason: '.$invoice->void_reason : '' }}</div>
@endif

@if (count($payments))
    <div class="section">
        <h3 class="label">Payments received</h3>
        <table class="pay">
            @foreach ($payments as $payment)
                <tr>
                    <td style="width: 26%;">{{ $day($payment->received_on) }}</td>
                    <td style="text-transform: capitalize;">{{ str_replace('_', ' ', $payment->method) }}@if ($payment->payment_reference) <span class="muted">({{ $payment->payment_reference }})</span>@endif</td>
                    <td class="num">{{ $cur((string) $payment->amount) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif

@if ($invoice->notes)
    <div class="section">
        <h3 class="label">Notes</h3>
        <div class="note">{{ $invoice->notes }}</div>
    </div>
@endif
</body>
</html>
