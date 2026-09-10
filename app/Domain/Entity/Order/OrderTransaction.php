<?php

namespace App\Domain\Entity\Order;

use App\Domain\Entity\Currency\CurrencyEnum;
use App\Domain\Entity\Order\Exceptions\AppendOnlyViolationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderTransaction extends Model
{

    /** @use HasFactory<\Database\Factories\Domain\Entity\Order\OrderTransactionFactory> */
    use HasFactory;
    use HasUuids;

    private const int MONEY_SCALE = 2;

    protected $guarded = ['id'];

    protected $casts = [
        'type'         => OrderTransactionTypeEnum::class,
        'status'       => OrderTransactionStatusEnum::class,
        'currency'     => CurrencyEnum::class,
        'price'        => 'decimal:2',
        'processed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $transaction): void {
            throw new AppendOnlyViolationException(
                "Order transaction {$transaction->id} is append-only and cannot be updated."
            );
        });

        static::deleting(static function (self $transaction): void {
            throw new AppendOnlyViolationException(
                "Order transaction {$transaction->id} is append-only and cannot be deleted."
            );
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @param  iterable<int, OrderItem>  $items
     * @return Collection<int, self>
     */
    public static function chargeItems(
        iterable $items,
        \DateTimeInterface $processedAt,
        ?string $externalId = null
    ): Collection
    {
        return Collection::make($items)->map(
            static fn(OrderItem $item): self => static::charge($item, $processedAt, $externalId)
        );
    }

    public static function charge(
        OrderItem $item,
        \DateTimeInterface $processedAt,
        ?string $externalId = null
    ): self
    {
        return static::record(
            $item,
            OrderTransactionTypeEnum::CHARGE,
            OrderTransactionStatusEnum::SUCCEEDED,
            $externalId,
            null,
            $processedAt
        );
    }

    public static function refund(
        OrderItem $item,
        ?\DateTimeInterface $processedAt = null,
        ?string $externalId = null
    ): self
    {
        return static::record(
            $item,
            OrderTransactionTypeEnum::REFUND,
            OrderTransactionStatusEnum::SUCCEEDED,
            $externalId,
            null,
            $processedAt
        );
    }

    public static function refundRejected(
        OrderItem $item,
        string $reason,
        ?\DateTimeInterface $processedAt = null,
        ?string $externalId = null
    ): self
    {
        return static::record(
            $item,
            OrderTransactionTypeEnum::REFUND,
            OrderTransactionStatusEnum::FAILED,
            $externalId,
            $reason,
            $processedAt
        );
    }

    public static function refundKey(OrderItem $item): string
    {
        return "refund:{$item->id}";
    }

    public static function findRecorded(
        string $orderItemId,
        OrderTransactionTypeEnum $type,
        OrderTransactionStatusEnum $status
    ): ?self
    {
        return static::query()
                     ->where('order_item_id', $orderItemId)
                     ->where('type', $type->value)
                     ->where('status', $status->value)
                     ->first();
    }

    public static function sumCharged(?string $orderId = null): string
    {
        return static::sumOf(OrderTransactionTypeEnum::CHARGE, $orderId);
    }

    public static function sumRefunded(?string $orderId = null): string
    {
        return static::sumOf(OrderTransactionTypeEnum::REFUND, $orderId);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, self> */
    public static function rejectedRefunds(): \Illuminate\Database\Eloquent\Collection
    {
        return static::query()
                     ->where('type', OrderTransactionTypeEnum::REFUND->value)
                     ->where('status', OrderTransactionStatusEnum::FAILED->value)
                     ->whereNotExists(static function ($query): void {
                         $query->select(DB::raw(1))
                               ->from('order_transactions as ok')
                               ->whereColumn('ok.order_item_id', 'order_transactions.order_item_id')
                               ->where('ok.type', OrderTransactionTypeEnum::REFUND->value)
                               ->where('ok.status', OrderTransactionStatusEnum::SUCCEEDED->value);
                     })
                     ->orderBy('processed_at')
                     ->get();
    }

    public static function sumChargedUntil(string $orderId, \DateTimeInterface $moment): string
    {
        return static::sumUntil(OrderTransactionTypeEnum::CHARGE, $orderId, $moment);
    }

    public static function sumRefundedUntil(string $orderId, \DateTimeInterface $moment): string
    {
        return static::sumUntil(OrderTransactionTypeEnum::REFUND, $orderId, $moment);
    }

    public static function sumChargedBetween(\DateTimeInterface $from, \DateTimeInterface $to): string
    {
        return static::sumBetween(OrderTransactionTypeEnum::CHARGE, $from, $to);
    }

    public static function sumRefundedBetween(\DateTimeInterface $from, \DateTimeInterface $to): string
    {
        return static::sumBetween(OrderTransactionTypeEnum::REFUND, $from, $to);
    }

    public static function orderCountBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return static::succeeded()
                     ->whereBetween('processed_at', [$from, $to])
                     ->distinct()
                     ->count('order_id');
    }

    private static function sumUntil(
        OrderTransactionTypeEnum $type,
        string $orderId,
        \DateTimeInterface $moment
    ): string
    {
        return static::money(static::succeeded()
                                   ->where('order_id', $orderId)
                                   ->where('type', $type->value)
                                   ->where('processed_at', '<=', $moment)
                                   ->sum('price'));
    }

    private static function sumBetween(
        OrderTransactionTypeEnum $type,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): string
    {
        return static::money(static::succeeded()
                                   ->where('type', $type->value)
                                   ->whereBetween('processed_at', [$from, $to])
                                   ->sum('price'));
    }

    /** @return array<string, mixed> */
    public static function balance(?string $orderId = null): array
    {
        $totals = static::succeeded()
                        ->when($orderId, static fn($query) => $query->where('order_id', $orderId))
                        ->selectRaw(
                            <<<'SQL'
                            coalesce(sum(price) filter (where type = ?), 0) as charged,
                            coalesce(sum(price) filter (
                                where type = ? and order_item_id in (
                                    select id from order_items where status = ?
                                )
                            ), 0) as delivered,
                            coalesce(sum(price) filter (where type = ?), 0) as refunded
                            SQL,
                            [
                                OrderTransactionTypeEnum::CHARGE->value,
                                OrderTransactionTypeEnum::CHARGE->value,
                                OrderItemStatusEnum::DELIVERED->value,
                                OrderTransactionTypeEnum::REFUND->value,
                            ]
                        )
                        ->toBase()
                        ->first();

        return static::totals(
            (string) $totals->charged,
            (string) $totals->delivered,
            (string) $totals->refunded,
        );
    }

    /**
     * @param  iterable<int, self>  $transactions
     * @param  iterable<int, OrderItem>  $items
     * @return array<string, mixed>
     */
    public static function balanceOf(iterable $transactions, iterable $items): array
    {
        $deliveredItems = [];

        foreach ($items as $item) {
            if ($item->status === OrderItemStatusEnum::DELIVERED) {
                $deliveredItems[$item->id] = true;
            }
        }

        $charged = $delivered = $refunded = '0.00';

        foreach ($transactions as $transaction) {
            if ($transaction->status !== OrderTransactionStatusEnum::SUCCEEDED) {
                continue;
            }

            $price = (string) $transaction->price;

            if ($transaction->type === OrderTransactionTypeEnum::REFUND) {
                $refunded = bcadd($refunded, $price, self::MONEY_SCALE);

                continue;
            }

            $charged = bcadd($charged, $price, self::MONEY_SCALE);

            if (isset($deliveredItems[$transaction->order_item_id])) {
                $delivered = bcadd($delivered, $price, self::MONEY_SCALE);
            }
        }

        return static::totals($charged, $delivered, $refunded);
    }

    /** @return array<string, mixed> */
    private static function totals(string $charged, string $delivered, string $refunded): array
    {
        $inFlight = bcsub(bcsub($charged, $delivered, self::MONEY_SCALE), $refunded, self::MONEY_SCALE);

        return [
            'charged_total'   => (float) $charged,
            'delivered_total' => (float) $delivered,
            'refunded_total'  => (float) $refunded,
            'in_flight_total' => (float) $inFlight,
            'settled'         => bccomp($inFlight, '0', self::MONEY_SCALE) === 0,
        ];
    }

    private static function record(
        OrderItem $item,
        OrderTransactionTypeEnum $type,
        OrderTransactionStatusEnum $status,
        ?string $externalId = null,
        ?string $failedReason = null,
        ?\DateTimeInterface $processedAt = null
    ): self
    {
        $transaction                     = new static();
        $transaction->order_id           = $item->order_id;
        $transaction->order_item_id      = $item->id;
        $transaction->vendor_external_id = $externalId;
        $transaction->price              = $item->price;
        $transaction->currency           = $item->currency;
        $transaction->type               = $type;
        $transaction->status             = $status;
        $transaction->failed_reason      = $failedReason;
        $transaction->processed_at       = $processedAt ?? now();

        try {
            $transaction->save();

            return $transaction;
        } catch (UniqueConstraintViolationException $e) {
            $existing = static::findRecorded($item->id, $type, $status);

            if (!$existing) {
                throw $e;
            }

            return $existing;
        }
    }

    private static function sumOf(OrderTransactionTypeEnum $type, ?string $orderId): string
    {
        return static::money(static::succeeded()
                                   ->where('type', $type->value)
                                   ->when($orderId, static fn($query) => $query->where('order_id', $orderId))
                                   ->sum('price'));
    }

    private static function money(mixed $sum): string
    {
        return bcadd((string) ($sum ?: 0), '0', self::MONEY_SCALE);
    }

    private static function succeeded(): Builder
    {
        return static::query()->where('status', OrderTransactionStatusEnum::SUCCEEDED->value);
    }

}
