@php
    $currency = $order->store->currency ?? '$';
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
    <h1>{{ $order->store->name ?? 'POS' }}</h1>
    <div class="muted">Receipt {{ $order->receipt_number }}</div>
    <div class="muted">{{ $order->created_at?->format('Y-m-d H:i') }}</div>
    <div class="muted">Cashier: {{ $order->cashier->name ?? '—' }}</div>
</div>

<hr>

<table class="row">
    @foreach($order->items as $item)
        <tr>
            <td colspan="2"><strong>{{ $item->product_name }}</strong></td>
        </tr>
        <tr>
            <td class="muted">{{ $item->product_sku }} · {{ $item->quantity }} × {{ $currency }}{{ number_format((float) $item->unit_price, 2) }}</td>
            <td class="r">{{ $currency }}{{ number_format((float) $item->line_total, 2) }}</td>
        </tr>
        @if((float) $item->discount_amount > 0)
            <tr>
                <td class="muted">  discount {{ number_format((float) $item->discount_percent, 0) }}%</td>
                <td class="r muted">−{{ $currency }}{{ number_format((float) $item->discount_amount, 2) }}</td>
            </tr>
        @endif
    @endforeach
</table>

<hr>

<table class="row">
    <tr>
        <td>Subtotal</td>
        <td class="r">{{ $currency }}{{ number_format((float) $order->subtotal, 2) }}</td>
    </tr>
    @if((float) $order->discount_amount > 0)
        <tr>
            <td>Discount</td>
            <td class="r">−{{ $currency }}{{ number_format((float) $order->discount_amount, 2) }}</td>
        </tr>
    @endif
    @if((float) $order->tax_amount > 0)
        <tr>
            <td>Tax</td>
            <td class="r">{{ $currency }}{{ number_format((float) $order->tax_amount, 2) }}</td>
        </tr>
    @endif
    <tr class="total">
        <td>TOTAL</td>
        <td class="r">{{ $currency }}{{ number_format((float) $order->total_amount, 2) }}</td>
    </tr>
</table>

<hr>

<table class="row">
    <tr>
        <td>{{ strtoupper($order->payment_method) }}</td>
        <td class="r">{{ $currency }}{{ number_format((float) $order->amount_paid, 2) }}</td>
    </tr>
    @if((float) $order->change_amount > 0)
        <tr>
            <td>Change</td>
            <td class="r">{{ $currency }}{{ number_format((float) $order->change_amount, 2) }}</td>
        </tr>
    @endif
</table>

@if($order->customer_name || $order->customer_phone || $order->customer_email)
    <hr>
    <div class="muted">
        @if($order->customer_name)<div>{{ $order->customer_name }}</div>@endif
        @if($order->customer_phone)<div>{{ $order->customer_phone }}</div>@endif
        @if($order->customer_email)<div>{{ $order->customer_email }}</div>@endif
    </div>
@endif

@if($order->notes)
    <hr>
    <div class="muted">{{ $order->notes }}</div>
@endif

<hr>

<div class="center muted">Thank you!</div>

</body>
</html>
