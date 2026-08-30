<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\FinanceVendor;
use Illuminate\Foundation\Http\FormRequest;

class FinanceVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vendor = $this->route('vendor');

        return $vendor
            ? $this->user()->can('update', $vendor)
            : $this->user()->can('create', FinanceVendor::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
