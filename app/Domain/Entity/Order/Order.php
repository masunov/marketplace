<?php

namespace App\Domain\Entity\Order;

use App\Domain\Entity\Currency\CurrencyEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\Entity\Order\OrderFactory> */
    use HasFactory;
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'status'                    => OrderStatusEnum::class,
        'currency'                  => CurrencyEnum::class,
        'total_amount'              => 'decimal:2',
    ];

    public static function findById(string $id): ?self
    {
        return static::query()->where('id', $id)->first();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OrderTransaction::class);
    }

    public function tryTransitionTo(OrderStatusEnum $from, OrderStatusEnum $to, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($from, $to, $reason): bool {
            $updated = static::query()
                             ->whereKey($this->id)
                             ->where('status', $from->value)
                             ->update([
                                 'status'     => $to->value,
                                 'updated_at' => now(),
                             ]);

            if ($updated !== 1) {
                return false;
            }

            $this->setAttribute('status', $to);
            $this->syncOriginalAttribute('status');

            OrderProcessingLog::recordOrderStatus($this, $from, $to, $reason);

            return true;
        });
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function closedWithUnsettledMoney(): \Illuminate\Support\Collection
    {
        return collect(DB::select(<<<'SQL'
            SELECT o.id AS order_id,
                   o.status,
                   t.charged,
                   t.delivered,
                   t.refunded,
                   t.charged - t.delivered - t.refunded AS unsettled
              FROM orders o
              JOIN LATERAL (
                  SELECT
                    coalesce(sum(price) filter (
                        where type = 'charge' and status = 'succeeded'), 0) AS charged,
                    coalesce(sum(price) filter (
                        where type = 'charge' and status = 'succeeded'
                          and order_item_id in (
                              select id from order_items where status = 'delivered')), 0) AS delivered,
                    coalesce(sum(price) filter (
                        where type = 'refund' and status = 'succeeded'), 0) AS refunded
                  FROM order_transactions
                 WHERE order_id = o.id
              ) t ON true
             WHERE o.status IN ('delivered', 'partially_delivered', 'refunded')
               AND abs(t.charged - t.delivered - t.refunded) > 0.005
             ORDER BY o.updated_at
        SQL));
    }

    /** @return Collection<int, self> */
    public static function unpaidLongerThan(\DateTimeInterface $stale, int $limit): Collection
    {
        return static::query()
                     ->where('status', OrderStatusEnum::CREATED->value)
                     ->where('created_at', '<=', $stale)
                     ->orderBy('created_at')
                     ->limit($limit)
                     ->get();
    }

    /** @return Collection<int, self> */
    public static function recentlyTouched(int $limit): Collection
    {
        return static::query()
                     ->orderByDesc('updated_at')
                     ->limit($limit)
                     ->get();
    }

    /** @return Collection<int, self> */
    public static function stalledBeforeFinalize(int $limit): Collection
    {
        $unfinished = array_map(
            static fn(OrderItemStatusEnum $status): string => $status->value,
            array_filter(
                OrderItemStatusEnum::cases(),
                static fn(OrderItemStatusEnum $status): bool => !$status->isDeliveryFinished()
            )
        );

        return static::query()
                     ->whereIn('status', [OrderStatusEnum::PAID->value, OrderStatusEnum::DELIVERING->value])
                     ->whereExists(static function ($query): void {
                         $query->select(DB::raw(1))
                               ->from('order_items')
                               ->whereColumn('order_items.order_id', 'orders.id');
                     })
                     ->whereNotExists(static function ($query) use ($unfinished): void {
                         $query->select(DB::raw(1))
                               ->from('order_items')
                               ->whereColumn('order_items.order_id', 'orders.id')
                               ->whereIn('order_items.status', $unfinished);
                     })
                     ->orderBy('updated_at')
                     ->limit($limit)
                     ->get();
    }

    /**
     * @param  array<int, OrderStatusEnum>  $statuses
     * @return Collection<int, self>
     */
    public static function recoverable(array $statuses, \DateTimeInterface $stale, int $limit): Collection
    {
        return static::query()
                     ->whereIn('status', array_map(static fn(OrderStatusEnum $s): string => $s->value, $statuses))
                     ->where(static function ($query) use ($stale): void {
                         $query->whereNotIn('status', [OrderStatusEnum::PAID->value, OrderStatusEnum::DELIVERING->value])
                               ->orWhere('updated_at', '<=', $stale);
                     })
                     ->orderBy('updated_at')
                     ->limit($limit)
                     ->get();
    }

    /** @return Collection<int, self> */

    /** @return Collection<int, self> */

    /** @return Collection<int, self> */

}
