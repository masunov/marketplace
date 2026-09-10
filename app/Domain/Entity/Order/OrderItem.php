<?php

namespace App\Domain\Entity\Order;

use App\Domain\Entity\Currency\CurrencyEnum;
use App\Domain\Entity\Product\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class OrderItem extends Model
{

    /** @use HasFactory<\Database\Factories\Domain\Entity\Order\OrderItemFactory> */
    use HasFactory;
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'status'                    => OrderItemStatusEnum::class,
        'currency'                  => CurrencyEnum::class,
        'price'                     => 'decimal:2',
        'delivery_cycle_started_at' => 'immutable_datetime',
    ];

    public static function findById(string $id): ?self
    {
        return static::query()->where('id', $id)->first();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function relatedContent(): MorphTo
    {
        return $this->morphTo();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OrderTransaction::class);
    }

    public function attachRelatedContent(IOrderContent $content): void
    {
        $this->relatedContent()->associate($content);
        $this->save();
    }

    public function tryTransitionTo(
        OrderItemStatusEnum $from,
        OrderItemStatusEnum $to,
        ?string $reason = null
    ): bool
    {
        return DB::transaction(function () use ($from, $to, $reason): bool {
            $attributes = [
                'status'     => $to->value,
                'updated_at' => now(),
            ];

            if ($to === OrderItemStatusEnum::DELIVERING) {
                $attributes['delivery_cycle_started_at'] = now();
            }

            $updated = static::query()
                             ->whereKey($this->id)
                             ->where('status', $from->value)
                             ->update($attributes);

            if ($updated !== 1) {
                return false;
            }

            $this->setAttribute('status', $to);
            $this->syncOriginalAttribute('status');

            if ($to === OrderItemStatusEnum::DELIVERING) {
                $this->setAttribute('delivery_cycle_started_at', $attributes['delivery_cycle_started_at']);
                $this->syncOriginalAttribute('delivery_cycle_started_at');
            }

            OrderProcessingLog::recordItemStatus($this, $from, $to, $reason);

            return true;
        });
    }

    /** @return Collection<int, self> */
    public static function forOrder(string $orderId): Collection
    {
        return static::query()
                     ->where('order_id', $orderId)
                     ->orderBy('created_at')
                     ->orderBy('id')
                     ->get();
    }

    public static function allDeliveryFinished(string $orderId): bool
    {
        return !static::query()
                      ->where('order_id', $orderId)
                      ->whereNotIn('status', static::deliveryFinishedStatuses())
                      ->exists();
    }

    /** @return Collection<int, self> */
    public static function awaitingRefund(?string $orderId = null): Collection
    {
        return static::query()
                     ->when($orderId, static fn($query) => $query->where('order_id', $orderId))
                     ->whereIn('status', static::refundableStatuses())
                     ->whereNotExists(static function ($query): void {
                         $query->select(DB::raw(1))
                               ->from('order_transactions')
                               ->whereColumn('order_transactions.order_item_id', 'order_items.id')
                               ->where('order_transactions.type', OrderTransactionTypeEnum::REFUND->value)
                               ->where('order_transactions.status', OrderTransactionStatusEnum::SUCCEEDED->value);
                     })
                     ->orderBy('updated_at')
                     ->get();
    }

    /** @return array<string, int> */
    public static function countByStatus(): array
    {
        return static::query()
                     ->select('status', DB::raw('count(*) as total'))
                     ->groupBy('status')
                     ->pluck('total', 'status')
                     ->map(static fn($count): int => (int) $count)
                     ->all();
    }

    /** @return Collection<int, self> */
    public static function paidButNotSettled(\DateTimeInterface $stale): Collection
    {
        return static::query()
                     ->whereNotIn('status', [
                         OrderItemStatusEnum::DELIVERED->value,
                         OrderItemStatusEnum::REFUNDED->value,
                     ])
                     ->where('updated_at', '<=', $stale)
                     ->whereExists(static function ($query): void {
                         $query->select(DB::raw(1))
                               ->from('order_transactions')
                               ->whereColumn('order_transactions.order_item_id', 'order_items.id')
                               ->where('order_transactions.type', OrderTransactionTypeEnum::CHARGE->value)
                               ->where('order_transactions.status', OrderTransactionStatusEnum::SUCCEEDED->value);
                     })
                     ->orderBy('updated_at')
                     ->get();
    }

    /** @return Collection<int, self> */
    public static function contentWithoutDeliveredStatus(): Collection
    {
        return static::query()
                     ->whereNotNull('related_content_id')
                     ->where('status', '!=', OrderItemStatusEnum::DELIVERED->value)
                     ->orderBy('updated_at')
                     ->get();
    }

    /**
     * @param  array<int, OrderItemStatusEnum>  $statuses
     * @return Collection<int, self>
     */
    public static function recoverable(array $statuses, \DateTimeInterface $stale, int $limit): Collection
    {
        return static::query()
                     ->whereIn('status', array_map(
                         static fn(OrderItemStatusEnum $status): string => $status->value,
                         $statuses
                     ))
                     ->whereExists(static function ($query): void {
                         $query->select(DB::raw(1))
                               ->from('orders')
                               ->whereColumn('orders.id', 'order_items.order_id')
                               ->whereNotIn('orders.status', [
                                   OrderStatusEnum::CREATED->value,
                                   OrderStatusEnum::PAYMENT_FAILED->value,
                               ]);
                     })
                     ->where(static function ($query) use ($stale): void {
                         $query->where('status', '!=', OrderItemStatusEnum::DELIVERING->value)
                               ->orWhere('updated_at', '<=', $stale);
                     })
                     ->orderBy('updated_at')
                     ->limit($limit)
                     ->get();
    }

    /** @return array<int, string> */
    private static function deliveryFinishedStatuses(): array
    {
        return array_map(
            static fn(OrderItemStatusEnum $status): string => $status->value,
            array_filter(
                OrderItemStatusEnum::cases(),
                static fn(OrderItemStatusEnum $status): bool => $status->isDeliveryFinished()
            )
        );
    }

    /** @return array<int, string> */
    private static function refundableStatuses(): array
    {
        return array_map(
            static fn(OrderItemStatusEnum $status): string => $status->value,
            array_filter(
                OrderItemStatusEnum::cases(),
                static fn(OrderItemStatusEnum $status): bool => $status->needsRefund()
            )
        );
    }

}
