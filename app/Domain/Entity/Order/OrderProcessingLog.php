<?php

namespace App\Domain\Entity\Order;

use App\Domain\Entity\ProductVendor\Vendor;
use App\Domain\Entity\ProductVendor\VendorEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class OrderProcessingLog extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\Entity\Order\OrderProcessingLogFactory> */
    use HasFactory;
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'order_status'    => OrderStatusEnum::class,
        'previous_status' => OrderStatusEnum::class,
        'vendor'          => VendorEnum::class,
        'result'          => OrderProcessingResultEnum::class,
        'triggered_at'    => 'immutable_datetime',
    ];


    public static function countAttemptsByVendor(VendorEnum $vendor, string $orderId): int
    {
        return static::query()
                     ->where('order_id', $orderId)
                     ->where('vendor', $vendor->name->value)
                     ->count();
    }

    /**
     * @return Collection<string, array{attempts: int, unknown: int, failed: int, out_of_stock: int}>
     */
    public static function vendorStatsForCycle(Order $order): Collection
    {
        return static::query()
                     ->where('order_id', $order->getKey())
                     ->where('request_id', $order->request_id)
                     ->whereNotNull('vendor')
                     ->when(
                         $order->delivery_cycle_started_at,
                         static fn($query) => $query->where('triggered_at', '>=', $order->delivery_cycle_started_at)
                     )
                     ->groupBy('vendor')
                     ->selectRaw(
                         'vendor,'
                         . ' count(*) as attempts,'
                         . ' count(*) filter (where result in (?, ?)) as unknown,'
                         . ' count(*) filter (where result in (?, ?)) as failed,'
                         . ' count(*) filter (where result = ?) as out_of_stock',
                         [
                             OrderProcessingResultEnum::TIMEOUT->value,
                             OrderProcessingResultEnum::IN_FLIGHT->value,
                             OrderProcessingResultEnum::ERROR->value,
                             OrderProcessingResultEnum::OUT_OF_STOCK->value,
                             OrderProcessingResultEnum::OUT_OF_STOCK->value,
                         ]
                     )
                     ->get()
                     ->mapWithKeys(static fn(self $row): array => [
                         $row->vendor->value => [
                             'attempts'     => (int) $row->attempts,
                             'unknown'      => (int) $row->unknown,
                             'failed'       => (int) $row->failed,
                             'out_of_stock' => (int) $row->out_of_stock,
                         ],
                     ]);
    }

    public static function startAttempt(Order $order, Vendor $vendor): self
    {
        return static::query()->create([
            'order_id'     => $order->getKey(),
            'order_status' => $order->status,
            'vendor'       => $vendor->name,
            'request_id'   => $order->request_id,
            'result'       => OrderProcessingResultEnum::IN_FLIGHT,
            'triggered_at' => now(),
        ]);
    }

    public function finishAttempt(OrderProcessingResultEnum $result): void
    {
        $this->result = $result;
        $this->save();
    }

}
