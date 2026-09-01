<?php
declare(strict_types=1);

namespace App\Listeners\Orders;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Events\Order\OrderDelivering;
use App\Domain\Events\Order\OrderPaid;
use App\Jobs\Orders\OrderKeyDeliveringAttemptJob;
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
                'order_id'     => $order->getKey(),
                'order_status' => $order->status->value,
            ]);

            return;
        }

        Log::info('order.delivery.started', [
            'order_id'   => $order->getKey(),
            'request_id' => $order->request_id,
        ]);

        OrderDelivering::dispatch($order->getKey(), OrderStatusEnum::PAID);

        OrderKeyDeliveringAttemptJob::dispatch($order->getKey())
                                    ->onConnection('redis')
                                    ->onQueue('order-key-delivering-attempt-queue');
    }
}
