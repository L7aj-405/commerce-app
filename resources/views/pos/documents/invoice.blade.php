@php
    $currency = $order->store->currency ?? '$';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #1f2937; margin: 0; }
        h1 { font-size: 22pt; margin: 0 0 4px; }
        h2 { font-size: 12pt; margin: 18px 0 6px; }
        .muted { color: #6b7280; font-size: 9pt; }
        .right { text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        thead th { background: #f3f4f6; text-align: left; padding: 8px; font-size: 9pt; text-transform: uppercase; letter-spacing: 0.05em; }
        tbody td { padding: 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        tfoot td { padding: 6px 8px; }
        tfoot .total td { border-top: 2px solid #1f2937; font-size: 13pt; font-weight: bold; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 9999px; background: #ecfdf5; color: #047857; font-size: 9pt; }
    </style>
</head>
<body>

<table>
    <tr>
        <td>
            <h1>{{ $order->store->name ?? 'Invoice' }}</h1>
            <div class="muted">Store ID: {{ $order->store_id }}</div>
        </td>
        <td class="right">
            <div><strong>Invoice</strong> #{{ $order->receipt_number }}</div>
            <div class="muted">Issued: {{ $order->created_at?->format('Y-m-d H:i') }}</div>
            <div class="muted">Cashier: {{ $order->cashier->name ?? '—' }}</div>
            <div style="margin-top:6px;"><span class="pill">{{ ucfirst($order->status) }}</span></div>
        </td>
    </tr>
</table>

@if($order->customer_name || $order->customer_phone || $order->customer_email)
    <h2>Bill to</h2>
    <div>{{ $order->customer_name ?: '—' }}</div>
    @if($order->customer_phone)<div class="muted">{{ $order->customer_phone }}</div>@endif
    @if($order->customer_email)<div class="muted">{{ $order->customer_email }}</div>@endif
@endif

<h2>Items</h2>

<table>
    <thead>
        <tr>
            <th>Item</th>
            <th class="right">Qty</th>
            <th class="right">Unit</th>
            <th class="right">Discount</th>
            <th class="right">Tax</th>
            <th class="right">Line total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($order->items as $item)
            <tr>
                <td>
                    <div><strong>{{ $item->product_name }}</strong></div>
                    <div class="muted">{{ $item->product_sku }}</div>
                </td>
                <td class="right">{{ $item->quantity }}</td>
                <td class="right">{{ $currency }}{{ number_format((float) $item->unit_price, 2) }}</td>
                <td class="right">
                    @if((float) $item->discount_amount > 0)
                        −{{ $currency }}{{ number_format((float) $item->discount_amount, 2) }}
                        <div class="muted">{{ number_format((float) $item->discount_percent, 1) }}%</div>
                    @else
                        —
                    @endif
                </td>
                <td class="right">
                    @if((float) $item->tax_amount > 0)
                        {{ $currency }}{{ number_format((float) $item->tax_amount, 2) }}
                        <div class="muted">{{ number_format((float) $item->tax_percent, 1) }}%</div>
                    @else
                        —
                    @endif
                </td>
                <td class="right">{{ $currency }}{{ number_format((float) $item->line_total, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" class="right">Subtotal</td>
            <td class="right">{{ $currency }}{{ number_format((float) $order->subtotal, 2) }}</td>
        </tr>
        @if((float) $order->discount_amount > 0)
            <tr>
                <td colspan="5" class="right">Discount</td>
                <td class="right">−{{ $currency }}{{ number_format((float) $order->discount_amount, 2) }}</td>
            </tr>
        @endif
        @if((float) $order->tax_amount > 0)
            <tr>
                <td colspan="5" class="right">Tax</td>
                <td class="right">{{ $currency }}{{ number_format((float) $order->tax_amount, 2) }}</td>
            </tr>
        @endif
        <tr class="total">
            <td colspan="5" class="right">Total</td>
            <td class="right">{{ $currency }}{{ number_format((float) $order->total_amount, 2) }}</td>
        </tr>
    </tfoot>
</table>

<h2>Payment</h2>
<table>
    <tr>
        <td>Method</td>
        <td class="right">{{ strtoupper($order->payment_method) }}</td>
    </tr>
    <tr>
        <td>Amount paid</td>
        <td class="right">{{ $currency }}{{ number_format((float) $order->amount_paid, 2) }}</td>
    </tr>
    @if((float) $order->change_amount > 0)
        <tr>
            <td>Change given</td>
            <td class="right">{{ $currency }}{{ number_format((float) $order->change_amount, 2) }}</td>
        </tr>
    @endif
</table>

@if($order->notes)
    <h2>Notes</h2>
    <p class="muted">{{ $order->notes }}</p>
@endif

</body>
</html>
