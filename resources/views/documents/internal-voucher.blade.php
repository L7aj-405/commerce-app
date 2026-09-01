@php
    /** @var \App\Models\FinanceExpense $expense */
    $currency = $expense->currency ?? $expense->store->currency ?? 'MAD';
    $reviewLabel = $expense->owner_review_status?->label();
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans, sans-serif; color: #1a1a1a; font-size: 12px; }
        .header { width: 100%; margin-bottom: 20px; }
        .header td { vertical-align: top; }
        .brand { font-size: 20px; font-weight: bold; color: #4f46e5; }
        .muted { color: #6b7280; }
        h1 { font-size: 20px; margin: 0; letter-spacing: 1px; }
        .banner { margin: 14px 0 20px; padding: 8px 12px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; border-radius: 4px; font-size: 11px; }
        table.fields { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.fields td { padding: 7px 8px; border: 1px solid #e5e7eb; vertical-align: top; }
        table.fields td.label { width: 32%; background: #f9fafb; font-weight: bold; color: #374151; }
        .amount { font-size: 16px; font-weight: bold; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: bold; text-transform: uppercase; background: #e0e7ff; color: #3730a3; }
        .signatures { width: 100%; margin-top: 46px; border-collapse: collapse; }
        .signatures td { width: 33.33%; vertical-align: top; padding: 0 10px; }
        .sig-box { border-top: 1px solid #111827; padding-top: 6px; margin-top: 46px; font-size: 10px; color: #374151; text-align: center; }
        .sig-role { font-weight: bold; font-size: 11px; color: #111827; }
        .footer { margin-top: 40px; font-size: 9px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width:60%">
                <div class="brand">{{ $expense->organization->name ?? 'Organization' }}</div>
                @if($expense->store)<div class="muted">{{ $expense->store->name }}</div>@endif
            </td>
            <td style="width:40%" class="right" style="text-align:right">
                <h1>INTERNAL CASH VOUCHER</h1>
                <div class="muted">Bon de caisse interne</div>
                <div class="muted">Ref: {{ $expense->reference ?: $expense->id }}</div>
            </td>
        </tr>
    </table>

    <div class="banner">
        Internal justification only — NOT an official fiscal invoice or receipt. Proves internally who paid, who
        received the money, why, when and how much. See the attached expense for any official documents.
    </div>

    <table class="fields">
        <tr>
            <td class="label">Date</td>
            <td>{{ $expense->expense_date?->format('Y-m-d') }}</td>
            <td class="label">Amount</td>
            <td class="amount">{{ number_format((float) $expense->amount, 2) }} {{ $currency }}</td>
        </tr>
        <tr>
            <td class="label">Title</td>
            <td colspan="3">{{ $expense->title }}</td>
        </tr>
        <tr>
            <td class="label">Category</td>
            <td>{{ $expense->category->name ?? '—' }}</td>
            <td class="label">Payment method</td>
            <td>{{ $expense->payment_method?->value ? ucfirst(str_replace('_', ' ', $expense->payment_method->value)) : '—' }}</td>
        </tr>
        <tr>
            <td class="label">Beneficiary (received)</td>
            <td>{{ $expense->beneficiary_name ?: '—' }}</td>
            <td class="label">Paid by</td>
            <td>{{ $expense->paid_by ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Reason / business purpose</td>
            <td colspan="3">{{ $expense->justification_reason ?: '—' }}</td>
        </tr>
        @if($expense->justification_notes)
            <tr>
                <td class="label">Notes</td>
                <td colspan="3">{{ $expense->justification_notes }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Created by</td>
            <td>{{ $expense->createdBy->name ?? '—' }}</td>
            <td class="label">Owner review</td>
            <td>
                @if($reviewLabel)
                    <span class="status">{{ $reviewLabel }}</span>
                    @if($expense->ownerReviewedBy)
                        <div class="muted" style="margin-top:3px">{{ $expense->ownerReviewedBy->name }} · {{ $expense->owner_reviewed_at?->format('Y-m-d') }}</div>
                    @endif
                @else
                    —
                @endif
            </td>
        </tr>
    </table>

    <table class="signatures">
        <tr>
            <td>
                <div class="sig-box">
                    <div class="sig-role">Paid by</div>
                    <div class="muted">Cashier / accountant signature</div>
                </div>
            </td>
            <td>
                <div class="sig-box">
                    <div class="sig-role">Received by</div>
                    <div class="muted">Beneficiary signature</div>
                </div>
            </td>
            <td>
                <div class="sig-box">
                    <div class="sig-role">Approved by</div>
                    <div class="muted">Owner signature (optional)</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">Printed {{ now()->format('Y-m-d H:i') }} — internal document, for internal transparency and owner control only.</div>
</body>
</html>
