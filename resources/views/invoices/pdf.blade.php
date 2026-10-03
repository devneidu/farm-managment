<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $invoice->reference }}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; }
    h1 { font-size: 22px; margin: 0 0 4px 0; }
    .muted { color: #6b7280; }
    .row { width: 100%; margin-bottom: 18px; }
    .row td { vertical-align: top; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.lines th { text-align: left; border-bottom: 2px solid #111827; padding: 6px 4px; }
    table.lines td { border-bottom: 1px solid #e5e7eb; padding: 6px 4px; }
    .num { text-align: right; }
    .totals { width: 45%; margin-left: 55%; margin-top: 14px; border-collapse: collapse; }
    .totals td { padding: 4px; }
    .grand td { border-top: 2px solid #111827; font-weight: bold; font-size: 13px; }
    .stamp { display: inline-block; padding: 3px 10px; border: 2px solid #111827; font-weight: bold; text-transform: uppercase; }
</style>
</head>
<body>
<table class="row">
    <tr>
        <td>
            <h1>Invoice</h1>
            <div>{{ $invoice->reference }}</div>
            <div class="muted">Sale {{ $invoice->sale->reference }}</div>
        </td>
        <td class="num">
            <div><strong>{{ $invoice->seller_name }}</strong></div>
            <div class="muted">Issued {{ $invoice->issue_date->toDateString() }}</div>
            @if ($invoice->due_date)
                <div class="muted">Due {{ $invoice->due_date->toDateString() }}</div>
            @endif
            <div><span class="stamp">{{ str_replace('_', ' ', $status) }}</span></div>
        </td>
    </tr>
</table>

<table class="row">
    <tr>
        <td>
            <div class="muted">Billed to</div>
            <div><strong>{{ $invoice->customer_name ?? 'Walk-in customer' }}</strong></div>
            @if ($invoice->customer_address) <div>{{ $invoice->customer_address }}</div> @endif
            @if ($invoice->customer_phone) <div>{{ $invoice->customer_phone }}</div> @endif
            @if ($invoice->customer_email) <div>{{ $invoice->customer_email }}</div> @endif
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr><th>#</th><th>Description</th><th>Quantity</th><th class="num">Amount ({{ $invoice->currency }})</th></tr>
    </thead>
    <tbody>
    @foreach ($invoice->items as $line)
        <tr>
            <td>{{ $line->line_no }}</td>
            <td>{{ $line->description }}</td>
            <td>{{ $line->quantity_label }}</td>
            <td class="num">{{ $money((string) $line->amount) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr class="grand"><td>Total</td><td class="num">{{ $invoice->currency }} {{ $money((string) $invoice->total_amount) }}</td></tr>
    <tr><td>Paid</td><td class="num">{{ $invoice->currency }} {{ $money($paid) }}</td></tr>
    <tr><td><strong>Balance due</strong></td><td class="num"><strong>{{ $invoice->currency }} {{ $money($outstanding) }}</strong></td></tr>
</table>

@if (count($payments))
    <p class="muted" style="margin-top:18px;">Payments received</p>
    @foreach ($payments as $payment)
        <div>{{ $payment->received_on->toDateString() }} - {{ str_replace('_', ' ', $payment->method) }} - {{ $invoice->currency }} {{ $money((string) $payment->amount) }}@if ($payment->payment_reference) ({{ $payment->payment_reference }})@endif</div>
    @endforeach
@endif

@if ($invoice->notes)
    <p class="muted" style="margin-top:18px;">Notes</p>
    <div>{{ $invoice->notes }}</div>
@endif
</body>
</html>
