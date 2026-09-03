@php
    $l = $label;
    $money = fn ($v) => number_format((float) $v, 2) . ' ' . ($l['currency'] ?? 'MAD');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans, sans-serif; color: #111827; font-size: 10px; }
        .label { border: 2px solid #111827; padding: 8px; }
        .warn {
            background: #fef3c7; border: 1px solid #d97706; color: #7c2d12;
            padding: 4px 6px; font-size: 8px; font-weight: bold;
            text-transform: uppercase; letter-spacing: .5px; text-align: center; margin-bottom: 8px;
        }
        .top { width: 100%; margin-bottom: 6px; }
        .top td { vertical-align: top; }
        .prov { font-size: 15px; font-weight: bold; }
        .sub { color: #6b7280; font-size: 8px; text-transform: uppercase; letter-spacing: .5px; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bc { text-align: center; margin: 8px 0 2px; }
        .track { font-family: dejavusansmono, monospace; font-size: 14px; font-weight: bold; letter-spacing: 1px; text-align: center; }
        .block { border-top: 1px solid #e5e7eb; padding-top: 6px; margin-top: 6px; }
        .k { font-size: 7px; text-transform: uppercase; color: #6b7280; letter-spacing: .5px; }
        .v { font-size: 11px; font-weight: bold; color: #111827; }
        .cod { font-size: 15px; font-weight: bold; }
        .foot { margin-top: 8px; font-size: 7px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
<div class="label">
    <div class="warn">Internal fallback label — not an official Ozon PDF</div>

    <table class="top">
        <tr>
            <td style="width: 58%">
                <div class="prov">{{ $l['provider'] }}</div>
                <div class="sub">BL {{ $l['bl_ref'] ?? '—' }}</div>
            </td>
            <td style="width: 42%" class="right">
                <div class="k">Order</div>
                <div class="v">{{ $l['order_reference'] }}</div>
            </td>
        </tr>
    </table>

    @if (! empty($l['tracking_number']))
        <div class="bc">
            <barcode code="{{ $l['tracking_number'] }}" type="C128B" size="1" height="1" />
        </div>
    @endif
    <div class="track">{{ $l['tracking_number'] ?: '—' }}</div>

    <div class="block">
        <div class="k">Consignee</div>
        <div class="v">{{ $l['customer_name'] }}</div>
        @if (! empty($l['phone']))<div>{{ $l['phone'] }}</div>@endif
        @if (! empty($l['city']))<div>{{ $l['city'] }}</div>@endif
        @if (! empty($l['address']))<div>{{ $l['address'] }}</div>@endif
    </div>

    <div class="block">
        <table style="width: 100%">
            <tr>
                <td style="width: 55%">
                    <div class="k">COD to collect</div>
                    <div class="cod">{{ $money($l['cod_amount'] ?? 0) }}</div>
                </td>
                <td style="width: 45%" class="right">
                    <div class="k">Sender</div>
                    <div class="v">{{ $l['sender_name'] }}</div>
                    @if (! empty($l['sender_phone']))<div>{{ $l['sender_phone'] }}</div>@endif
                </td>
            </tr>
        </table>
    </div>

    <div class="foot">
        Generated {{ optional($l['generated_at'])->format('Y/m/d H:i') }} · Use the official Ozon Bon de Livraison PDF when available.
    </div>
</div>
</body>
</html>
