<?php
declare(strict_types=1);

namespace App\Jobs\Orders;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Order\OrderItemStatusEnum;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/** @deprecated Заменена на OrderItemDeliveringAttemptJob. Класс оставлен только ради заданий, */
class OrderKeyDeliveringAttemptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 1;

    public function __construct(public readonly string $orderId)
    {

    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->orderId))->releaseAfter(30)->expireAfter(120),
        ];
    }

    public function handle(): void
    {
        Log::warning('order.delivery.deprecated_job', ['order_id' => $this->orderId]);

        $order = Order::findById($this->orderId);

        if (!$order) {
            Log::warning('order.delivery.order_not_found', ['order_id' => $this->orderId]);

            return;
        }

        $items = OrderItem::forOrder($order->id)
                          ->reject(static fn(OrderItem $item): bool => $item->status->isDeliveryFinished());

        if ($items->isEmpty()) {
            Log::info('order.delivery.nothing_to_hand_over', ['order_id' => $order->id]);

            return;
        }

        $items->each(fn(OrderItem $item) => $this->handOver($item));
    }

    private function handOver(OrderItem $item): void
    {
        if ($item->status === OrderItemStatusEnum::PENDING
            && !$item->tryTransitionTo(OrderItemStatusEnum::PENDING, OrderItemStatusEnum::DELIVERING)) {
            return;
        }

        Log::info('order.delivery.handed_over_to_item_job', [
            'order_id'      => $item->order_id,
            'order_item_id' => $item->id,
        ]);

        OrderItemDeliveringAttemptJob::dispatch($item->id)
                                     ->onQueue(OrderItemDeliveringAttemptJob::deliveryQueue());
    }
}
