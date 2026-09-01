<?php

namespace App\Domain\Entity\Order;

use App\Domain\Entity\Currency\CurrencyEnum;
use App\Domain\Entity\Product\Product;
use App\Domain\Entity\PaymentSystem\PaymentStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
        'price'                     => 'decimal:2',
        'delivery_cycle_started_at' => 'immutable_datetime',
        'type'                      => OrderTypeEnum::class,
    ];

    public static function findById(string $id): ?self
    {
        return static::query()->where('id', $id)->first();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function relatedContent(): MorphTo
    {
        return $this->morphTo();
    }

    public function attachRelatedContent(IOrderContent $content): void
    {
        $this->relatedContent()->associate($content);
        $this->save();
    }

    public function tryTransitionTo(OrderStatusEnum $from, OrderStatusEnum $to): bool
    {
        $attributes = [
            'status'     => $to->value,
            'updated_at' => now(),
        ];

        if ($to === OrderStatusEnum::DELIVERING) {
            $attributes['delivery_cycle_started_at'] = now();
        }

        $updated = static::query()
                         ->whereKey($this->getKey())
                         ->where('status', $from->value)
                         ->update($attributes);

        if ($updated === 1) {
            $this->setAttribute('status', $to);
            $this->syncOriginalAttribute('status');

            if ($to === OrderStatusEnum::DELIVERING) {
                $this->setAttribute('delivery_cycle_started_at', $attributes['delivery_cycle_started_at']);
                $this->syncOriginalAttribute('delivery_cycle_started_at');
            }
        }

        return $updated === 1;
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

    /**
     * @return Collection<int, self>
     */
    public static function paidButNotIssued(\DateTimeInterface $stale): Collection
    {
        return static::reconciliationBase()
                     ->whereExists(static::paidCallbackExists())
                     ->whereNotExists(static::vendorKeyExists())
                     ->where('orders.updated_at', '<=', $stale)
                     ->orderBy('orders.updated_at')
                     ->get();
    }

    /**
     * @return Collection<int, self>
     */
    public static function issuedButNotPaid(): Collection
    {
        return static::reconciliationBase()
                     ->whereExists(static::vendorKeyExists())
                     ->whereNotExists(static::paidCallbackExists())
                     ->orderBy('orders.updated_at')
                     ->get();
    }

    /**
     * @return Collection<int, self>
     */
    public static function issuedButNotDelivered(): Collection
    {
        return static::reconciliationBase()
                     ->whereExists(static::vendorKeyExists())
                     ->where('orders.status', '!=', OrderStatusEnum::DELIVERED->value)
                     ->orderBy('orders.updated_at')
                     ->get();
    }

    public static function sumPaid(): float
    {
        return (float) static::query()->whereExists(static::paidCallbackExists())->sum('price');
    }

    public static function sumPaidAndIssued(): float
    {
        return (float) static::query()
                             ->whereExists(static::paidCallbackExists())
                             ->whereExists(static::vendorKeyExists())
                             ->sum('price');
    }

    public static function sumPaidNotIssued(): float
    {
        return (float) static::query()
                             ->whereExists(static::paidCallbackExists())
                             ->whereNotExists(static::vendorKeyExists())
                             ->sum('price');
    }

    public static function sumIssuedNotPaid(): float
    {
        return (float) static::query()
                             ->whereExists(static::vendorKeyExists())
                             ->whereNotExists(static::paidCallbackExists())
                             ->sum('price');
    }

    private static function reconciliationBase(): Builder
    {
        return static::query()->select(
            'orders.id',
            'orders.status',
            'orders.price',
            'orders.currency',
            'orders.updated_at'
        );
    }

    private static function paidCallbackExists(): \Closure
    {
        return static function ($query): void {
            $query->select(DB::raw(1))
                  ->from('payment_callback_logs')
                  ->whereColumn('payment_callback_logs.order_id', 'orders.id')
                  ->where('payment_callback_logs.payment_status', PaymentStatusEnum::PAID->value)
                  ->whereNotNull('payment_callback_logs.processed_at');
        };
    }

    private static function vendorKeyExists(): \Closure
    {
        return static function ($query): void {
            $query->select(DB::raw(1))
                  ->from('vendor_keys')
                  ->whereColumn('vendor_keys.order_id', 'orders.id');
        };
    }

}
