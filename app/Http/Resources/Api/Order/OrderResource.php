<?php

namespace App\Http\Resources\Api\Order;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'status'       => $this->status->value,
            'total_amount' => $this->total_amount,
            'currency'     => $this->currency->value,
            'items'        => OrderItemResource::collection($this->items),
            'money'        => OrderTransaction::balanceOf($this->transactions, $this->items),
            'created_at'   => $this->created_at?->toDateTimeString(),
            'updated_at'   => $this->updated_at?->toDateTimeString(),
        ];
    }
}
