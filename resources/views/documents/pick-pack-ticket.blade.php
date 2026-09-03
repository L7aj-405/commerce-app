@php
    /** @var \App\Services\Documents\ResolvedDocumentTemplate $template */
    $d = $data;
    $L = fn (string $k, string $fallback = '') => $template->label($k, $fallback);
    $show = fn (string $f) => $template->fieldVisible($f);
    $dash = fn ($v) => (is_null($v) || $v === '') ? '—' : $v;
    $money = fn ($v) => number_format((float) $v, 2) . ' ' . ($d['currency'] ?? 'MAD');
    $barcodePos = $template->barcodePosition();
    $barcodeType = $template->barcodeType();
    $ref = (string) ($d['order_reference'] ?? '');
    $headerText = $template->setting('header_text') ?: ($d['store_name'] ?? '');
    $footerText = $template->setting('footer_text') ?: 'Internal document — this is NOT a carrier label.';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: {{ $template->setting('font', 'dejavusans') }}, sans-serif; color: #000; font-size: 10px; }
        .muted { color: #555; }
        .mono { font-family: dejavusansmono, monospace; }
        .right { text-align: right; }
        .center { text-align: center; }
        h1 { font-size: 15px; margin: 0 0 2px; letter-spacing: .5px; }
        .brand { font-size: 13px; font-weight: bold; }
        .band { background: #000; color: #fff; padding: 2px 5px; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .kv { margin-top: 8px; }
        .kv td { padding: 3px 6px; border: 1px solid #999; vertical-align: top; }
        .kv .k { width: 26%; font-size: 8px; text-transform: uppercase; color: #444; background: #f0f0f0; }
        .sec { margin-top: 10px; }
        .sec-title { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1.5px solid #000; padding-bottom: 2px; margin-bottom: 4px; }
        table.items th { background: #000; color: #fff; text-align: left; padding: 4px 5px; font-size: 8px; text-transform: uppercase; }
        table.items td { padding: 4px 5px; border-bottom: 1px solid #bbb; font-size: 9px; vertical-align: top; }
        table.items tr:nth-child(even) td { background: #f6f6f6; }
        .qty { text-align: center; font-weight: bold; white-space: nowrap; }
        .box { display: inline-block; width: 11px; height: 11px; border: 1.5px solid #000; }
        .cod { font-size: 14px; font-weight: bold; }
        .prepaid { border: 2px solid #000; padding: 1px 6px; font-weight: bold; font-size: 10px; }
        .check td { padding: 3px 4px; font-size: 9px; }
        .sign { margin-top: 14px; }
        .sign td { width: 33%; padding: 4px 8px 0; vertical-align: bottom; }
        .sign .line { border-bottom: 1px solid #000; height: 26px; }
        .sign .role { font-size: 8px; text-transform: uppercase; color: #444; margin-top: 2px; }
        .foot { margin-top: 12px; border-top: 1px solid #000; padding-top: 3px; font-size: 8px; }
        .warn { margin-top: 6px; font-size: 9px; font-weight: bold; }
    </style>
</head>
<body>

<table class="head">
    <tr>
        <td style="width: 62%">
            @if ($template->setting('show_logo', true) && $headerText !== '')
                <div class="brand">{{ $headerText }}</div>
            @endif
            @if (! empty($d['organization_name']) && $d['organization_name'] !== ($d['store_name'] ?? null))
                <div class="muted">{{ $d['organization_name'] }}</div>
            @endif
            @if ($show('warehouse') && ! empty($d['warehouse_name']))
                <div class="muted">Warehouse: {{ $d['warehouse_name'] }}</div>
            @endif
            <h1 style="margin-top:4px">{{ $L('title', 'Pick / Pack Ticket') }}</h1>
            <div class="band">Internal — not a carrier label</div>
        </td>
        <td style="width: 38%" class="right">
            @if ($barcodePos === 'header' && $ref !== '')
                <barcode code="{{ $ref }}" type="{{ $barcodeType }}" size="0.9" height="0.9" />
                <div class="mono" style="font-size:9px">{{ $ref }}</div>
            @endif
        </td>
    </tr>
</table>

<table class="kv">
    <tr>
        <td class="k">{{ $L('order', 'Order') }}</td>
        <td class="mono">{{ $dash($ref) }}</td>
        <td class="k">{{ $L('order_date', 'Order date') }}</td>
        <td>{{ $dash($d['order_date'] ?? null) }}</td>
    </tr>
    <tr>
        <td class="k">{{ $L('internal_id', 'Internal ID') }}</td>
        <td class="mono">{{ $dash($d['order_id'] ?? null) }}</td>
        <td class="k">{{ $L('printed', 'Printed') }}</td>
        <td>{{ $dash($d['printed_at'] ?? null) }}@if(!empty($d['printed_by'])) · {{ $d['printed_by'] }}@endif</td>
    </tr>
</table>

<div class="sec">
    <div class="sec-title">{{ $L('customer', 'Customer') }} / {{ $L('address', 'Address') }}</div>
    <table class="kv" style="margin-top:0">
        <tr>
            <td class="k">{{ $L('customer', 'Customer') }}</td>
            <td>{{ $dash($d['customer_name'] ?? null) }}</td>
            @if ($show('phone'))
                <td class="k">{{ $L('phone', 'Phone') }}</td>
                <td class="mono">{{ $dash($d['phone'] ?? null) }}</td>
            @else
                <td colspan="2"></td>
            @endif
        </tr>
        <tr>
            @if ($show('city'))
                <td class="k">{{ $L('city', 'City') }}</td>
                <td>{{ $dash($d['city'] ?? null) }}</td>
            @else
                <td colspan="2"></td>
            @endif
            @if ($show('address'))
                <td class="k">{{ $L('address', 'Address') }}</td>
                <td>{{ $dash($d['address'] ?? null) }}</td>
            @else
                <td colspan="2"></td>
            @endif
        </tr>
        @if ($show('notes') && ! empty($d['notes']))
            <tr>
                <td class="k">{{ $L('notes', 'Delivery notes') }}</td>
                <td colspan="3">{{ $d['notes'] }}</td>
            </tr>
        @endif
    </table>
</div>

@if ($show('payment'))
    <div class="sec">
        <div class="sec-title">{{ $L('payment', 'Payment') }}</div>
        <table>
            <tr>
                <td style="width:60%">
                    {{ $L('payment', 'Payment') }}: <strong>{{ $dash($d['payment_method'] ?? null) }}</strong>
                    @if (! empty($d['is_prepaid']))
                        &nbsp;<span class="prepaid">{{ $L('prepaid', 'PREPAID') }}</span>
                    @endif
                </td>
                <td class="right">
                    @if ($show('cod_amount') && empty($d['is_prepaid']))
                        <span class="muted" style="font-size:8px;text-transform:uppercase">{{ $L('cod_amount', 'COD to collect') }}</span><br>
                        <span class="cod">{{ $money($d['cod_amount'] ?? 0) }}</span>
                    @endif
                </td>
            </tr>
        </table>
    </div>
@endif

<div class="sec">
    <div class="sec-title">{{ $L('items', 'Items to pick') }}
        @if (! empty($d['units_total'])) ({{ (int) $d['units_total'] }} {{ \Illuminate\Support\Str::plural('unit', (int) $d['units_total']) }}) @endif
    </div>
    @if (empty($d['items']))
        <div class="warn">⚠ No items found on this order — verify the order before packing.</div>
    @else
        <table class="items">
            <thead>
                <tr>
                    <th style="width:6%">#</th>
                    <th>{{ $L('product', 'Product') }}</th>
                    @if ($show('sku'))<th style="width:18%">{{ $L('sku', 'SKU') }}</th>@endif
                    <th style="width:8%" class="center">{{ $L('qty', 'Qty') }}</th>
                    <th style="width:9%" class="center">{{ $L('pick', 'Pick') }}</th>
                    <th style="width:9%" class="center">{{ $L('pack', 'Pack') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($d['items'] as $i => $it)
                    <tr>
                        <td class="center">{{ $i + 1 }}</td>
                        <td>
                            {{ $dash($it['name'] ?? null) }}
                            @if (! empty($it['variant']))<div class="muted">{{ $L('variant', 'Variant') }}: {{ $it['variant'] }}</div>@endif
                            @if ($show('barcode') && ! empty($it['barcode']))<div class="mono muted" style="font-size:8px">{{ $it['barcode'] }}</div>@endif
                        </td>
                        @if ($show('sku'))<td class="mono">{{ $dash($it['sku'] ?? null) }}</td>@endif
                        <td class="qty">{{ (int) ($it['quantity'] ?? 1) }}</td>
                        <td class="center"><span class="box"></span></td>
                        <td class="center"><span class="box"></span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

@if ($show('checklist'))
    <div class="sec">
        <div class="sec-title">{{ $L('checklist', 'Operational checklist') }}</div>
        <table class="check">
            @foreach ([
                'Products picked',
                'Quantities verified',
                'Parcel packed',
                'Invoice / receipt included (if applicable)',
                'Carrier label attached (added at dispatch)',
                'Ready for dispatch',
            ] as $line)
                <tr>
                    <td style="width:16px"><span class="box"></span></td>
                    <td>{{ $line }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif

@if ($show('signatures'))
    <table class="sign">
        <tr>
            <td><div class="line"></div><div class="role">{{ $L('picker', 'Picker') }} · date / time</div></td>
            <td><div class="line"></div><div class="role">{{ $L('packer', 'Packer') }} · date / time</div></td>
            <td><div class="line"></div><div class="role">{{ $L('dispatcher', 'Dispatcher') }} · date / time</div></td>
        </tr>
    </table>
@endif

<div class="foot">
    @if ($barcodePos === 'footer' && $ref !== '')
        <barcode code="{{ $ref }}" type="{{ $barcodeType }}" size="0.8" height="0.7" />
    @endif
    {{ $footerText }}
</div>

</body>
</html>
