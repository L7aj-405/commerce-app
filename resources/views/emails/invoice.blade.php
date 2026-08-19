<x-mail::message>
# Invoice {{ $facture->invoice_number }}

Hello {{ $facture->customer_name }},

Please find attached your invoice from **{{ $facture->store->name }}**.

- **Invoice:** {{ $facture->invoice_number }}
- **Date:** {{ $facture->invoice_date?->format('Y-m-d') }}
- **Total:** {{ number_format((float) $facture->total_amount, 2) }} {{ $facture->store->currency }}
@if($facture->amount_remaining > 0)- **Balance due:** {{ number_format($facture->amount_remaining, 2) }} {{ $facture->store->currency }}@endif

Thank you for your business.

Thanks,<br>
{{ $facture->store->name }}
</x-mail::message>
