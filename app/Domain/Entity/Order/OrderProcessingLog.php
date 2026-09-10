<?php

namespace App\Domain\Entity\Order;

use App\Domain\Entity\Concerns\CreatesWithoutFill;
use App\Domain\Services\ProductVendors\AttemptRequestId;
use App\Domain\Entity\Order\Exceptions\AppendOnlyViolationException;
use App\Domain\Entity\ProductVendor\VendorEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderProcessingLog extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\Entity\Order\OrderProcessingLogFactory> */
    use HasFactory;
    use HasUuids;
    use CreatesWithoutFill;

    protected $guarded = ['id'];

    protected $casts = [
        'type'                 => OrderProcessingTypeEnum::class,
        'order_status'         => OrderStatusEnum::class,
        'item_status'          => OrderItemStatusEnum::class,
        'previous_item_status' => OrderItemStatusEnum::class,
        'previous_status' => OrderStatusEnum::class,
        'vendor'          => VendorEnum::class,
        'result'          => OrderProcessingResultEnum::class,
        'triggered_at'    => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $log): void {
            throw new AppendOnlyViolationException(
                "Processing log {$log->id} is append-only and cannot be updated."
            );
        });

        static::deleting(static function (self $log): void {
            throw new AppendOnlyViolationException(
                "Processing log {$log->id} is append-only and cannot be deleted."
            );
        });
    }

    public static function recordOrderStatus(
        Order $order,
        OrderStatusEnum $from,
        OrderStatusEnum $to,
        ?string $reason = null
    ): void
    {
        static::createWithoutFill([
            'type'            => OrderProcessingTypeEnum::ORDER_STATUS_CHANGED,
            'order_id'        => $order->id,
            'order_status'    => $to,
            'previous_status' => $from,
            'reason'          => $reason,
            'triggered_at'    => now(),
        ]);
    }

    public static function recordItemStatus(
        OrderItem $item,
        OrderItemStatusEnum $from,
        OrderItemStatusEnum $to,
        ?string $reason = null
    ): void
    {
        static::createWithoutFill([
            'type'                 => OrderProcessingTypeEnum::ORDER_ITEM_STATUS_CHANGED,
            'order_id'             => $item->order_id,
            'order_item_id'        => $item->id,
            'item_status'          => $to,
            'previous_item_status' => $from,
            'request_id'           => $item->request_id,
            'reason'               => $reason,
            'triggered_at'         => now(),
        ]);
    }

    /** @return Collection<int, self> */
    public static function statusHistory(string $orderId, \DateTimeInterface $until): Collection
    {
        return static::query()
                     ->where('order_id', $orderId)
                     ->whereIn('type', [
                         OrderProcessingTypeEnum::ORDER_STATUS_CHANGED->value,
                         OrderProcessingTypeEnum::ORDER_ITEM_STATUS_CHANGED->value,
                     ])
                     ->where('triggered_at', '<=', $until)
                     ->orderBy('triggered_at')
                     ->orderBy('id')
                     ->get();
    }

    /** @return Collection<string, array{attempts: int, unknown: int, failed: int, out_of_stock: int}> */

    public static function nextAttemptNumber(OrderItem $item, VendorEnum $vendor): int
    {
        return static::attemptsForCycle($item)
                     ->where('vendor', $vendor->value)
                     ->count() + 1;
    }

    public static function attemptRequestId(OrderItem $item, VendorEnum $vendor, int $attemptNumber): string
    {
        return AttemptRequestId::for($item->request_id, $vendor, $attemptNumber);
    }

    public static function startVendorAttempt(
        OrderItem $item,
        VendorEnum $vendor,
        string $attemptRequestId
    ): self
    {
        return static::createWithoutFill([
            'type'               => OrderProcessingTypeEnum::VENDOR_ATTEMPT_STARTED,
            'order_id'           => $item->order_id,
            'order_item_id'      => $item->id,
            'order_status'       => $item->order?->status,
            'item_status'        => $item->status,
            'vendor'             => $vendor,
            'request_id'         => $item->request_id,
            'attempt_request_id' => $attemptRequestId,
            'result'             => OrderProcessingResultEnum::IN_FLIGHT,
            'triggered_at'       => now(),
        ]);
    }

    public function finishVendorAttempt(OrderProcessingResultEnum $result, ?string $reason = null): self
    {
        return static::createWithoutFill([
            'type'               => OrderProcessingTypeEnum::VENDOR_ATTEMPT_FINISHED,
            'parent_log_id'      => $this->id,
            'order_id'           => $this->order_id,
            'order_item_id'      => $this->order_item_id,
            'order_status'       => $this->order_status,
            'item_status'        => $this->item_status,
            'vendor'             => $this->vendor,
            'request_id'         => $this->request_id,
            'attempt_request_id' => $this->attempt_request_id,
            'result'             => $result,
            'reason'             => $reason,
            'triggered_at'       => now(),
        ]);
    }

    public static function unfinishedAttempt(OrderItem $item, VendorEnum $vendor): ?self
    {
        return static::attemptsForCycle($item)
                     ->where('vendor', $vendor->value)
                     ->whereNotExists(static function ($query): void {
                         $query->select(DB::raw(1))
                               ->from('order_processing_logs as finished')
                               ->whereColumn('finished.parent_log_id', 'order_processing_logs.id');
                     })
                     ->orderByDesc('triggered_at')
                     ->first();
    }

    /**
     * @return Collection<string, array{attempts: int, unknown: int, failed: int, out_of_stock: int, rejected: int}>
     */
    public static function vendorStatsForItemCycle(OrderItem $item): Collection
    {
        return static::attemptsForCycle($item)
                     ->leftJoin(
                         'order_processing_logs as finished',
                         'finished.parent_log_id',
                         '=',
                         'order_processing_logs.id'
                     )
                     ->groupBy('order_processing_logs.vendor')
                     ->selectRaw(
                         'order_processing_logs.vendor,'
                         . ' count(*) as attempts,'
                         . ' count(*) filter (where coalesce(finished.result, order_processing_logs.result) in (?, ?, ?)) as unknown,'
                         . ' count(*) filter (where coalesce(finished.result, order_processing_logs.result) in (?, ?)) as failed,'
                         . ' count(*) filter (where coalesce(finished.result, order_processing_logs.result) = ?) as out_of_stock,'
                         . ' count(*) filter (where coalesce(finished.result, order_processing_logs.result) in (?, ?)) as rejected',
                         [
                             OrderProcessingResultEnum::TIMEOUT->value,
                             OrderProcessingResultEnum::IN_FLIGHT->value,
                             OrderProcessingResultEnum::UNVERIFIED->value,
                             OrderProcessingResultEnum::ERROR->value,
                             OrderProcessingResultEnum::OUT_OF_STOCK->value,
                             OrderProcessingResultEnum::OUT_OF_STOCK->value,
                             OrderProcessingResultEnum::FOREIGN_CODE->value,
                             OrderProcessingResultEnum::DUPLICATE_CODE->value,
                         ]
                     )
                     ->get()
                     ->mapWithKeys(static fn(self $row): array => [
                         $row->vendor->value => [
                             'attempts'     => (int) $row->attempts,
                             'unknown'      => (int) $row->unknown,
                             'failed'       => (int) $row->failed,
                             'out_of_stock' => (int) $row->out_of_stock,
                             'rejected'     => (int) $row->rejected,
                         ],
                     ]);
    }

    private static function attemptsForCycle(OrderItem $item): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()
                     ->where('order_processing_logs.order_item_id', $item->id)
                     ->where('order_processing_logs.type', OrderProcessingTypeEnum::VENDOR_ATTEMPT_STARTED->value)
                     ->when(
                         $item->delivery_cycle_started_at,
                         static fn($query) => $query->where(
                             'order_processing_logs.triggered_at',
                             '>=',
                             $item->delivery_cycle_started_at
                         )
                     );
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function rejectedCodeSummary(\DateTimeInterface $since): \Illuminate\Support\Collection
    {
        return static::query()
                     ->where('triggered_at', '>=', $since)
                     ->whereIn('result', [
                         OrderProcessingResultEnum::FOREIGN_CODE->value,
                         OrderProcessingResultEnum::DUPLICATE_CODE->value,
                         OrderProcessingResultEnum::UNVERIFIED->value,
                     ])
                     ->groupBy('vendor', 'result')
                     ->selectRaw('vendor, result, count(*) as count')
                     ->get()
                     ->map(static fn(self $row): array => [
                         'vendor' => $row->vendor?->value,
                         'result' => $row->result?->value,
                         'count'  => (int) $row->count,
                     ]);
    }

}
