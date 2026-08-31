<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\DeliveryProviderFinanceSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A city fee must be attached to a real city — never raw text. Exactly one
 * of `provider_city_id` (preferred, the provider's own synced city — see
 * DeliveryProviderCity) or `city_id` (the internal canonical city list —
 * App\Models\City) is expected from the normal searchable-select UI.
 * `custom_city_name` exists ONLY as an admin-only escape hatch for when
 * neither city source has the city yet — never the default path.
 */
class DeliveryProviderCityFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', DeliveryProviderFinanceSetting::class);
    }

    public function rules(): array
    {
        $organization = $this->user()->getActiveStore()?->organization;
        $storeIds = $organization?->stores()->pluck('id') ?? collect();
        /** @var \App\Models\DeliveryProvider|null $provider */
        $provider = $this->route('provider');
        $isAdmin = $this->user()->isPrivilegedFor($this->user()->getActiveStore());

        return [
            'city_id' => [
                'nullable', 'string',
                Rule::exists('cities', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'provider_city_id' => [
                'nullable', 'string',
                Rule::exists('delivery_provider_cities', 'id')->where(function ($q) use ($storeIds, $provider) {
                    $q->whereIn('store_id', $storeIds->all());
                    if ($provider !== null) {
                        $q->where('provider_code', $provider->code);
                    }
                }),
            ],
            // Only an owner/admin may bypass city selection with free text —
            // anyone else submitting this field gets a clean validation
            // error, never a silently-ignored value.
            'custom_city_name' => [$isAdmin ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'delivery_fee' => ['required', 'numeric', 'min:0'],
            'return_fee' => ['nullable', 'numeric', 'min:0'],
            'refusal_fee' => ['nullable', 'numeric', 'min:0'],
            'cod_fee_fixed' => ['nullable', 'numeric', 'min:0'],
            'cod_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (blank($this->input('city_id')) && blank($this->input('provider_city_id')) && blank($this->input('custom_city_name'))) {
                $validator->errors()->add('city_id', 'Select a city.');
            }
        });
    }
}
