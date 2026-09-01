<?php

namespace App\Domain\Entity\ProductVendor;

use App\Domain\Entity\Order\IOrderContent;
use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Product\Product;
use App\Domain\Entity\Product\ProductTypeEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class VendorKey extends Model implements IOrderContent
{
    /** @use HasFactory<\Database\Factories\Domain\Entity\ProductVendor\VendorKeyFactory> */
    use HasFactory;
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'vendor'    => VendorEnum::class,
        'issued_at' => 'immutable_datetime',
    ];

    public static function morphAlias(): string
    {
        return 'vendor_key';
    }

    /**
     * @return array<int, ProductTypeEnum>
     */
    public static function supportedProductTypes(): array
    {
        return [
            ProductTypeEnum::KEY,
            ProductTypeEnum::TOPUP,
            ProductTypeEnum::SUBSCRIPTION,
            ProductTypeEnum::GIFTCARD,
        ];
    }

    public function contentValue(): string
    {
        return $this->key;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public static function issueForOrder(
        Order $order,
        VendorEnum $vendor,
        string $requestId,
        string $key
    ): self
    {
        try {
            return static::query()->create([
                'order_id'   => $order->getKey(),
                'product_id' => $order->product_id,
                'key'        => $key,
                'vendor'     => $vendor,
                'request_id' => $requestId,
                'issued_at'  => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $existing = static::query()->where('order_id', $order->getKey())->first();

            if (!$existing) {
                throw $e;
            }

            return $existing;
        }
    }

    public static function findByOrder(Order $order): ?self
    {
        return static::query()->where('order_id', $order->getKey())->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function orphanedAcrossVendors(): \Illuminate\Support\Collection
    {
        return collect(DB::select(<<<'SQL'
            SELECT o.id AS order_id,
                   o.status,
                   vk.vendor      AS delivered_by,
                   vk.key         AS delivered_key,
                   ek.vendor_name AS burned_at,
                   ek.key         AS burned_key
              FROM orders o
              JOIN vendor_keys vk ON vk.order_id = o.id
              JOIN external_vendor_keys ek ON ek.request_id = o.request_id::text
             WHERE ek.vendor_name <> vk.vendor
             ORDER BY o.updated_at
        SQL));
    }

}
