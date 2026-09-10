<?php

namespace App\Domain\Entity\ProductVendor;

use App\Domain\Entity\Concerns\CreatesWithoutFill;
use App\Domain\Entity\Order\IOrderContent;
use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Product\Product;
use App\Domain\Entity\Product\ProductTypeEnum;
use App\Domain\Entity\ProductVendor\Exceptions\DuplicateVendorCodeException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;

class VendorGiftCard extends Model implements IOrderContent
{

    /** @use HasFactory<\Database\Factories\Domain\Entity\ProductVendor\VendorGiftCardFactory> */
    use HasFactory;
    use HasUuids;
    use CreatesWithoutFill;

    protected $guarded = ['id'];

    protected $casts = [
        'vendor'    => VendorEnum::class,
        'issued_at' => 'immutable_datetime',
    ];

    public static function morphAlias(): string
    {
        return 'vendor_gift_card';
    }

    /** @return array<int, ProductTypeEnum> */
    public static function supportedProductTypes(): array
    {
        return [
            ProductTypeEnum::GIFTCARD,
        ];
    }

    public function contentValue(): string
    {
        return $this->code;
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public static function issueForItem(
        OrderItem $item,
        VendorEnum $vendor,
        string $attemptRequestId,
        string $code
    ): self
    {
        try {
            return static::createWithoutFill([
                'order_id'           => $item->order_id,
                'order_item_id'      => $item->id,
                'product_id'         => $item->product_id,
                'code'               => $code,
                'vendor'             => $vendor,
                'request_id'         => $item->request_id,
                'attempt_request_id' => $attemptRequestId,
                'issued_at'          => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $existing = static::findByItem($item);

            if (!$existing) {
                throw new DuplicateVendorCodeException(
                    "Gift card code already belongs to another order item.",
                    0,
                    $e
                );
            }

            return $existing;
        }
    }

    public static function findByItem(OrderItem $item): ?self
    {
        return static::query()->where('order_item_id', $item->id)->first();
    }

}
