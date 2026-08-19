@php
    /** @var \App\Models\Order $order */
    $currency = $order->store->currency ?? $order->currency ?? '$';
    $address  = data_get($order->platform_data, 'shipping.address_1')
        ?? data_get($order->platform_data, 'shipping.address');
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
        .tag { display: inline-block; border: 1px solid #000; padding: 0 3px; font-size: 7.5pt; }
    </style>
</head>
<body>

<div class="center">
    <h1>{{ $order->store->name ?? 'Store' }}</h1>
    <div class="muted">Order {{ $order->order_number }}</div>
    <div class="muted">{{ $order->created_at?->format('Y-m-d H:i') }}</div>
    <div><span class="tag">ONLINE ORDER</span></div>
</div>

<hr>

<table class="row">
    @foreach($items as $item)
        <tr>
            <td colspan="2"><strong>{{ $item['description'] }}</strong></td>
        </tr>
        <tr>
            <td class="muted">{{ $item['sku'] ?: '—' }} · {{ (float) $item['quantity'] }} × {{ $currency }}{{ number_format((float) $item['unit_price'], 2) }}</td>
            <td class="r">{{ $currency }}{{ number_format((float) $item['line_total'], 2) }}</td>
        </tr>
    @endforeach
</table>

<hr>

<table class="row">
    <tr>
        <td>Subtotal</td>
        <td class="r">{{ $currency }}{{ number_format((float) $totals['subtotal'], 2) }}</td>
    </tr>
    @if((float) $totals['discount_amount'] > 0)
        <tr>
            <td>Discount</td>
            <td class="r">−{{ $currency }}{{ number_format((float) $totals['discount_amount'], 2) }}</td>
        </tr>
    @endif
    @if((float) $totals['tax_amount'] > 0)
        <tr>
            <td>Tax</td>
            <td class="r">{{ $currency }}{{ number_format((float) $totals['tax_amount'], 2) }}</td>
        </tr>
    @endif
    <tr class="total">
        <td>TOTAL</td>
        <td class="r">{{ $currency }}{{ number_format((float) $totals['total_amount'], 2) }}</td>
    </tr>
</table>

@if($order->customer_name || $order->customer_phone || $order->customer_email || $address)
    <hr>
    <div class="muted">
        <div><strong>Deliver to</strong></div>
        @if($order->customer_name)<div>{{ $order->customer_name }}</div>@endif
        @if($order->customer_phone)<div>{{ $order->customer_phone }}</div>@endif
        @if($order->customer_email)<div>{{ $order->customer_email }}</div>@endif
        @if($address)<div>{{ $address }}</div>@endif
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
