<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\FinanceCodSettlement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceCodSettlementReconcileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $settlement = $this->route('settlement');

        return $settlement instanceof FinanceCodSettlement && $this->user()->can('update', $settlement);
    }

    public function rules(): array
    {
        $organizationId = $this->user()->getActiveStore()?->organization_id;

        /** @var FinanceCodSettlement $settlement */
        $settlement = $this->route('settlement');
        $expected = (float) ($settlement->expected_net_amount ?? $settlement->net_received);

        return [
            'actual_received_amount' => ['required', 'numeric'],
            'account_id' => [
                'nullable', 'string',
                Rule::exists('finance_accounts', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId)),
            ],
            'received_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            // A note is required whenever the entered amount doesn't match
            // what was expected — same tolerance settle() itself uses.
            'notes' => [
                Rule::requiredIf(fn () => abs(((float) $this->input('actual_received_amount', 0)) - $expected) > 0.001),
                'nullable', 'string', 'max:2000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'The received amount differs from what was expected — add a short note explaining why.',
        ];
    }
}
