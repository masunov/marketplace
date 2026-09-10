<?php
declare(strict_types=1);

namespace App\Listeners\Orders;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Order\OrderItemStatusEnum;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Events\Order\OrderDelivering;
use App\Domain\Events\Order\OrderPaid;
use App\Jobs\Orders\OrderItemDeliveringAttemptJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class StartOrderDeliveryListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(OrderPaid $event): void
    {
        $order = Order::findById($event->orderId);

        if (!$order) {
            Log::warning('order.delivery.order_not_found', ['order_id' => $event->orderId]);

            return;
        }

        if (!$order->tryTransitionTo(OrderStatusEnum::PAID, OrderStatusEnum::DELIVERING)) {
            Log::info('order.delivery.start_skipped', [
                'order_id'     => $order->id,
                'order_status' => $order->status->value,
            ]);

            return;
        }

        OrderDelivering::dispatch($order->id, OrderStatusEnum::PAID);

        foreach (OrderItem::forOrder($order->id) as $item) {
            $this->startItem($item);
        }
    }

    private function startItem(OrderItem $item): void
    {
        if (!$item->tryTransitionTo(OrderItemStatusEnum::PENDING, OrderItemStatusEnum::DELIVERING)) {
            Log::info('order.delivery.item_start_skipped', [
                'order_item_id' => $item->id,
                'item_status'   => $item->status->value,
            ]);

            return;
        }

        Log::info('order.delivery.item_started', [
            'order_id'      => $item->order_id,
            'order_item_id' => $item->id,
            'request_id'    => $item->request_id,
        ]);

        OrderItemDeliveringAttemptJob::dispatch($item->id)
                                     ->onConnection('redis')
                                     ->onQueue('order-key-delivering-attempt-queue');
    }
}
