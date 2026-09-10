<?php

namespace App\Http\Resources\Api\Order;

use App\Domain\Entity\Order\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderItem */
class OrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'      => $this->id,
            'status'  => $this->status->value,
            'price'   => $this->price,
            'product' => [
                'sku'  => $this->product->sku,
                'name' => $this->product->name,
                'type' => $this->product->type->value,
            ],
            'content' => $this->relatedContent
                ? [
                    'type'  => $this->related_content_type,
                    'value' => $this->relatedContent->contentValue(),
                ]
                : null,
        ];
    }
}
