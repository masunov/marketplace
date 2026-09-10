<?php

namespace App\Http\Requests\Api\PaymentSystems;

use App\Domain\Entity\Currency\CurrencyEnum;
use App\Domain\Entity\PaymentSystem\PaymentStatusEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PsMirCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'event_id'   => ['required', 'string', 'max:255'],
            'order_id'   => ['required', 'uuid'],
            'status'     => ['required', Rule::enum(PaymentStatusEnum::class)],
            'amount'     => ['required', 'numeric', 'min:0'],
            'currency'   => ['required', Rule::enum(CurrencyEnum::class)],
            'created_at' => ['required', 'date'],
        ];
    }
}
