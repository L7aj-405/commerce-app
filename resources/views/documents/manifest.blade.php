@php
    $store    = $manifest['store'];
    $currency = $manifest['currency'];
    $money = fn ($v) => number_format((float) $v, 2) . ' ' . $currency;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans, sans-serif; color: #1a1a1a; font-size: 11px; }
        .header { width: 100%; margin-bottom: 18px; }
        .header td { vertical-align: top; }
        .brand { font-size: 19px; font-weight: bold; color: #4f46e5; }
        .muted { color: #6b7280; }
        h1 { font-size: 20px; margin: 0; letter-spacing: 1px; }
        .right { text-align: right; }
        .center { text-align: center; }

        .meta { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        .meta td { padding: 6px 10px; background: #f3f4f6; border: 1px solid #e5e7eb; }
        .meta .label { font-size: 9px; text-transform: uppercase; color: #6b7280; display: block; }
        .meta .value { font-size: 13px; font-weight: bold; color: #111827; }

        table.parcels { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.parcels th { background: #111827; color: #fff; text-align: left; padding: 6px 7px; font-size: 9px; text-transform: uppercase; }
        table.parcels td { padding: 6px 7px; border-bottom: 1px solid #e5e7eb; font-size: 10px; vertical-align: top; }
        table.parcels tr:nth-child(even) td { background: #f9fafb; }
        .num { text-align: right; white-space: nowrap; }
        .idx { color: #6b7280; text-align: center; width: 22px; }
        .mono { font-family: dejavusansmono, monospace; font-size: 9px; }

        .totals { width: 100%; margin-top: 10px; }
        .totals td { padding: 6px 8px; }
        .totals .grand { font-size: 13px; font-weight: bold; border-top: 2px solid #111827; }

        .sign { width: 100%; margin-top: 40px; border-collapse: separate; border-spacing: 20px 0; }
        .sign td { width: 50%; vertical-align: top; }
        .sign .box { border: 1px solid #d1d5db; border-radius: 4px; padding: 12px; }
        .sign .role { font-size: 9px; text-transform: uppercase; color: #6b7280; letter-spacing: .5px; }
        .sign .who { font-size: 12px; font-weight: bold; margin-top: 2px; }
        .sign .line { border-bottom: 1px solid #9ca3af; height: 34px; margin-top: 18px; }
        .sign .hint { font-size: 8px; color: #9ca3af; margin-top: 3px; }

        .footer { margin-top: 26px; font-size: 9px; color: #9ca3af; text-align: center; }
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
                <h1>DELIVERY MANIFEST</h1>
                <div class="muted mono">{{ $manifest['reference'] }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td style="width:40%">
                <span class="label">Carrier</span>
                <span class="value">{{ $manifest['carrier'] }}</span>
            </td>
            <td style="width:20%">
                <span class="label">Type</span>
                <span class="value">{{ $manifest['carrier_type'] === 'internal' ? 'Internal agent' : 'Courier' }}</span>
            </td>
            <td style="width:20%" class="center">
                <span class="label">Parcels</span>
                <span class="value">{{ $manifest['total_parcels'] }}</span>
            </td>
            <td style="width:20%" class="right">
                <span class="label">Prepared</span>
                <span class="value">{{ $manifest['generated_at']->format('Y/m/d H:i') }}</span>
            </td>
        </tr>
    </table>

    <table class="parcels">
        <thead>
            <tr>
                <th class="idx">#</th>
                <th>Shipment</th>
                <th>Order</th>
                <th>Customer</th>
                <th>Delivery address</th>
                <th>Tracking</th>
                <th class="num">Value</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($manifest['parcels'] as $p)
                <tr>
                    <td class="idx">{{ $p['index'] }}</td>
                    <td class="mono">{{ $p['reference'] }}</td>
                    <td class="mono">{{ $p['order_reference'] }}</td>
                    <td>
                        {{ $p['customer_name'] }}
                        @if ($p['customer_phone'])
                            <div class="muted">{{ $p['customer_phone'] }}</div>
                        @endif
                    </td>
                    <td>{{ $p['address'] }}</td>
                    <td class="mono">{{ $p['tracking_number'] ?: '—' }}</td>
                    <td class="num">{{ $money($p['value']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td style="width:60%"></td>
            <td style="width:20%" class="right muted">Total parcels</td>
            <td style="width:20%" class="right"><strong>{{ $manifest['total_parcels'] }}</strong></td>
        </tr>
        <tr>
            <td></td>
            <td class="right grand">Total value (COD)</td>
            <td class="right grand">{{ $money($manifest['total_value']) }}</td>
        </tr>
    </table>

    <table class="sign">
        <tr>
            <td>
                <div class="box">
                    <span class="role">Handed over by</span>
                    <div class="who">{{ $store->name }}</div>
                    <div class="line"></div>
                    <div class="hint">Name · Signature · Date</div>
                </div>
            </td>
            <td>
                <div class="box">
                    <span class="role">Received by</span>
                    <div class="who">{{ $manifest['carrier'] }}</div>
                    <div class="line"></div>
                    <div class="hint">Name · Signature · Date</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ $manifest['reference'] }} — {{ $manifest['total_parcels'] }} parcel(s) handed to {{ $manifest['carrier'] }}
        on {{ $manifest['generated_at']->format('Y/m/d') }}. Both parties confirm the parcels listed above by signing.
    </div>
</body>
</html>
