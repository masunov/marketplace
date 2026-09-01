<?php
declare(strict_types=1);

namespace App\Listeners\Orders;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderProcessingLog;
use App\Domain\Events\Order\OrderStatusEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class LogOrderStatusChangeListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(OrderStatusEvent $event): void
    {
        $order = Order::findById($event->orderId);

        if (!$order) {
            Log::warning('order.status_log.order_not_found', [
                'order_id'     => $event->orderId,
                'order_status' => $event::status()->value,
            ]);

            return;
        }

        $log = new OrderProcessingLog();

        $log->order_id        = $event->orderId;
        $log->order_status    = $event::status();
        $log->previous_status = $event->previousStatus;
        $log->vendor          = null;
        $log->request_id      = $order->request_id;
        $log->result          = null;
        $log->reason          = $event->reason;
        $log->triggered_at    = $event->triggeredAt;
        $log->save();
    }
}
