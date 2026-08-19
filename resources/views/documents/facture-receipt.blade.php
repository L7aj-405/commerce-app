@php
    $store    = $facture->store;
    $currency = $store->currency ?? '';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        body { font-family: dejavusansmono, monospace; font-size: 9pt; margin: 0; padding: 0; color: #000; }
        .center { text-align: center; }
        .right  { text-align: right; }
        .row    { width: 100%; }
        .row td { vertical-align: top; padding: 1px 0; font-size: 8.5pt; }
        .row td.r { text-align: right; }
        hr { border: 0; border-top: 1px dashed #000; margin: 4px 0; }
        h1 { font-size: 11pt; margin: 2px 0; }
        .muted { color: #444; font-size: 7.5pt; }
        .total { font-size: 10pt; font-weight: bold; }
    </style>
</head>
<body>

<div class="center">
    <h1>{{ $store->name ?? 'Invoice' }}</h1>
    @if($store->address)<div class="muted">{{ $store->address }}</div>@endif
    <div class="muted">Invoice {{ $facture->invoice_number }}</div>
    <div class="muted">{{ ($facture->invoice_date ?? $facture->created_at)?->format('Y-m-d H:i') }}</div>
    <div class="muted">{{ ucfirst($facture->status) }}</div>
</div>

<hr>

<table class="row">
    @foreach($facture->items as $item)
        <tr>
            <td colspan="2"><strong>{{ $item->description }}</strong></td>
        </tr>
        <tr>
            <td class="muted">@if($item->sku){{ $item->sku }} · @endif{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }} × {{ $currency }}{{ number_format((float) $item->unit_price, 2) }}</td>
            <td class="r">{{ $currency }}{{ number_format((float) $item->line_total, 2) }}</td>
        </tr>
    @endforeach
</table>

<hr>

<table class="row">
    <tr>
        <td>Subtotal</td>
        <td class="r">{{ $currency }}{{ number_format((float) $facture->subtotal, 2) }}</td>
    </tr>
    @if((float) $facture->discount_amount > 0)
        <tr>
            <td>Discount</td>
            <td class="r">−{{ $currency }}{{ number_format((float) $facture->discount_amount, 2) }}</td>
        </tr>
    @endif
    @if((float) $facture->tax_amount > 0)
        <tr>
            <td>Tax</td>
            <td class="r">{{ $currency }}{{ number_format((float) $facture->tax_amount, 2) }}</td>
        </tr>
    @endif
    <tr class="total">
        <td>TOTAL</td>
        <td class="r">{{ $currency }}{{ number_format((float) $facture->total_amount, 2) }}</td>
    </tr>
</table>

<hr>

<table class="row">
    <tr>
        <td>Paid</td>
        <td class="r">{{ $currency }}{{ number_format((float) $facture->amount_paid, 2) }}</td>
    </tr>
    @if((float) $facture->amount_remaining > 0)
        <tr>
            <td>Balance due</td>
            <td class="r">{{ $currency }}{{ number_format((float) $facture->amount_remaining, 2) }}</td>
        </tr>
    @endif
</table>

@if($facture->customer_name || $facture->customer_phone || $facture->customer_email)
    <hr>
    <div class="muted">
        @if($facture->customer_name)<div>{{ $facture->customer_name }}</div>@endif
        @if($facture->customer_phone)<div>{{ $facture->customer_phone }}</div>@endif
        @if($facture->customer_email)<div>{{ $facture->customer_email }}</div>@endif
    </div>
@endif

@if($facture->notes)
    <hr>
    <div class="muted">{{ $facture->notes }}</div>
@endif

<hr>

<div class="center muted">Thank you!</div>

</body>
</html>
