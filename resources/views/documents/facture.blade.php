@php($store = $facture->store)
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans, sans-serif; color: #1a1a1a; font-size: 12px; }
        .header { width: 100%; margin-bottom: 24px; }
        .header td { vertical-align: top; }
        .brand { font-size: 20px; font-weight: bold; color: #4f46e5; }
        .muted { color: #6b7280; }
        h1 { font-size: 22px; margin: 0; letter-spacing: 1px; }
        .meta td { padding: 2px 0; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table.items th { background: #f3f4f6; text-align: left; padding: 8px; font-size: 11px; text-transform: uppercase; color: #374151; }
        table.items td { padding: 8px; border-bottom: 1px solid #e5e7eb; }
        .right { text-align: right; }
        .totals { width: 40%; margin-left: 60%; margin-top: 16px; }
        .totals td { padding: 4px 8px; }
        .totals .grand { font-size: 15px; font-weight: bold; border-top: 2px solid #111827; }
        .status { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-other { background: #e0e7ff; color: #3730a3; }
        .footer { margin-top: 40px; font-size: 10px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width:60%">
                <div class="brand">{{ $store->name }}</div>
                <div class="muted">{{ $store->address }}</div>
                <div class="muted">{{ $store->email }} · {{ $store->phone }}</div>
            </td>
            <td style="width:40%" class="right">
                <h1>INVOICE</h1>
                <div class="muted">{{ $facture->invoice_number }}</div>
                <span class="status {{ $facture->status === 'paid' ? 'status-paid' : 'status-other' }}">{{ $facture->status }}</span>
            </td>
        </tr>
    </table>

    <table class="meta" style="width:100%">
        <tr>
            <td style="width:60%">
                <strong>Bill To</strong><br>
                {{ $facture->customer_name }}<br>
                @if($facture->customer_email){{ $facture->customer_email }}<br>@endif
                @if($facture->customer_phone){{ $facture->customer_phone }}<br>@endif
                {{ $facture->customer_address }}
            </td>
            <td style="width:40%" class="right">
                <strong>Invoice date:</strong> {{ $facture->invoice_date?->format('Y-m-d') }}<br>
                @if($facture->due_date)<strong>Due date:</strong> {{ $facture->due_date->format('Y-m-d') }}<br>@endif
                <strong>Payment:</strong> {{ ucfirst($facture->payment_method) }}
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="right">Qty</th>
                <th class="right">Unit price</th>
                <th class="right">Line total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($facture->items as $item)
                <tr>
                    <td>{{ $item->description }}@if($item->sku)<br><span class="muted">{{ $item->sku }}</span>@endif</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float)$item->quantity, 2), '0'), '.') }}</td>
                    <td class="right">{{ number_format((float)$item->unit_price, 2) }}</td>
                    <td class="right">{{ number_format((float)$item->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="right">{{ number_format((float)$facture->subtotal, 2) }}</td></tr>
        @if((float)$facture->discount_amount > 0)<tr><td>Discount</td><td class="right">-{{ number_format((float)$facture->discount_amount, 2) }}</td></tr>@endif
        <tr><td>Tax</td><td class="right">{{ number_format((float)$facture->tax_amount, 2) }}</td></tr>
        <tr class="grand"><td>Total ({{ $store->currency }})</td><td class="right">{{ number_format((float)$facture->total_amount, 2) }}</td></tr>
        <tr><td>Paid</td><td class="right">{{ number_format((float)$facture->amount_paid, 2) }}</td></tr>
        <tr><td>Balance due</td><td class="right">{{ number_format($facture->amount_remaining, 2) }}</td></tr>
    </table>

    @if($facture->notes)<p class="muted">{{ $facture->notes }}</p>@endif

    <div class="footer">Generated {{ now()->format('Y-m-d H:i') }} · {{ $facture->invoice_number }}</div>
</body>
</html>
