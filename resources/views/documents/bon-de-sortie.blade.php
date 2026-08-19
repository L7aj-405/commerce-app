@php
    $store    = $slip['store'];
    $currency = $slip['currency'];
    $money = fn ($v) => number_format((float) $v, 2) . ' ' . $currency;
    $hasValue = ($slip['total_value'] ?? 0) > 0;
    $kindLabel = [
        'warehouse' => 'Warehouse transfer',
        'team'      => 'Team / internal post',
        'external'  => 'External exit',
    ][$slip['destination_kind']] ?? 'Stock exit';
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

        .route { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        .route td { width: 45%; padding: 10px 12px; border: 1px solid #e5e7eb; vertical-align: top; }
        .route .arrow { width: 10%; text-align: center; font-size: 18px; color: #4f46e5; border: none; }
        .route .role { font-size: 9px; text-transform: uppercase; color: #6b7280; letter-spacing: .5px; }
        .route .who { font-size: 14px; font-weight: bold; color: #111827; margin-top: 3px; }

        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th { background: #111827; color: #fff; text-align: left; padding: 6px 7px; font-size: 9px; text-transform: uppercase; }
        table.items td { padding: 6px 7px; border-bottom: 1px solid #e5e7eb; font-size: 10px; vertical-align: top; }
        table.items tr:nth-child(even) td { background: #f9fafb; }
        .num { text-align: right; white-space: nowrap; }
        .idx { color: #6b7280; text-align: center; width: 22px; }
        .mono { font-family: dejavusansmono, monospace; font-size: 9px; }

        .totals { width: 100%; margin-top: 10px; }
        .totals td { padding: 6px 8px; }
        .totals .grand { font-size: 13px; font-weight: bold; border-top: 2px solid #111827; }

        .notes { margin-top: 14px; padding: 10px 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px; font-size: 10px; }
        .notes .label { font-size: 9px; text-transform: uppercase; color: #6b7280; letter-spacing: .5px; }

        .sign { width: 100%; margin-top: 38px; border-collapse: separate; border-spacing: 20px 0; }
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
                <h1>BON DE SORTIE</h1>
                <div class="muted">Stock Exit Slip</div>
                <div class="muted mono">{{ $slip['reference'] }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td style="width:34%">
                <span class="label">Type</span>
                <span class="value">{{ $kindLabel }}</span>
            </td>
            <td style="width:22%">
                <span class="label">Transfer date</span>
                <span class="value">{{ $slip['transfer_date'] ? $slip['transfer_date']->format('Y/m/d') : '—' }}</span>
            </td>
            <td style="width:22%" class="center">
                <span class="label">Total units</span>
                <span class="value">{{ $slip['total_quantity'] }}</span>
            </td>
            <td style="width:22%" class="right">
                <span class="label">Prepared</span>
                <span class="value">{{ $slip['generated_at']->format('Y/m/d H:i') }}</span>
            </td>
        </tr>
    </table>

    <table class="route">
        <tr>
            <td>
                <span class="role">From (source)</span>
                <div class="who">{{ $slip['source'] }}</div>
            </td>
            <td class="arrow">&#8594;</td>
            <td>
                <span class="role">To (destination)</span>
                <div class="who">{{ $slip['destination'] }}</div>
                @if ($slip['responsible'])
                    <div class="muted" style="margin-top:4px">Responsible: {{ $slip['responsible'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th class="idx">#</th>
                <th>SKU</th>
                <th>Product</th>
                <th>Variant</th>
                <th class="num">Qty</th>
                @if ($hasValue)
                    <th class="num">Unit</th>
                    <th class="num">Value</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($slip['items'] as $item)
                <tr>
                    <td class="idx">{{ $item['index'] }}</td>
                    <td class="mono">{{ $item['sku'] ?: '—' }}</td>
                    <td>{{ $item['product'] }}</td>
                    <td>{{ $item['variant'] ?: '—' }}</td>
                    <td class="num">{{ $item['quantity'] }}</td>
                    @if ($hasValue)
                        <td class="num">{{ $item['unit_price'] !== null ? $money($item['unit_price']) : '—' }}</td>
                        <td class="num">{{ $item['line_value'] !== null ? $money($item['line_value']) : '—' }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td style="width:60%"></td>
            <td style="width:20%" class="right muted">Total units</td>
            <td style="width:20%" class="right"><strong>{{ $slip['total_quantity'] }}</strong></td>
        </tr>
        @if ($hasValue)
            <tr>
                <td></td>
                <td class="right grand">Total value</td>
                <td class="right grand">{{ $money($slip['total_value']) }}</td>
            </tr>
        @endif
    </table>

    @if ($slip['notes'])
        <div class="notes">
            <span class="label">Notes</span>
            <div style="margin-top:3px">{{ $slip['notes'] }}</div>
        </div>
    @endif

    <table class="sign">
        <tr>
            <td>
                <div class="box">
                    <span class="role">Released by</span>
                    <div class="who">{{ $slip['created_by'] ?: $store->name }}</div>
                    <div class="line"></div>
                    <div class="hint">Name · Signature · Date</div>
                </div>
            </td>
            <td>
                <div class="box">
                    <span class="role">Received by</span>
                    <div class="who">{{ $slip['responsible'] ?: $slip['destination'] }}</div>
                    <div class="line"></div>
                    <div class="hint">Name · Signature · Date</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ $slip['reference'] }} — {{ $slip['total_quantity'] }} unit(s) released from {{ $slip['source'] }}
        to {{ $slip['destination'] }} on {{ $slip['transfer_date'] ? $slip['transfer_date']->format('Y/m/d') : $slip['generated_at']->format('Y/m/d') }}.
        Both parties confirm the items listed above by signing.
    </div>
</body>
</html>
