<?php

namespace App\Http\Resources\Api\Order;

use App\Domain\Entity\Order\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->getKey(),
            'status'     => $this->status->value,
            'price'      => $this->price,
            'currency'   => $this->currency->value,
            'product'    => [
                'sku'  => $this->product->sku,
                'name' => $this->product->name,
                'type' => $this->product->type->value,
            ],
            'content'    => $this->relatedContent
                ? [
                    'type'  => $this->related_content_type,
                    'value' => $this->relatedContent->contentValue(),
                ]
                : null,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
