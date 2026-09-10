<?php

namespace App\Http\Requests\Api\Order;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OrderCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<int, string> */
    public function skus(): array
    {
        $validated = $this->validated();

        return isset($validated['items'])
            ? array_column($validated['items'], 'sku')
            : [$validated['sku']];
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'sku'         => 'required_without:items|string|exists:products,sku',
            'items'       => 'required_without:sku|array|min:1|max:20',
            'items.*.sku' => 'required|string|exists:products,sku',
        ];
    }
}
