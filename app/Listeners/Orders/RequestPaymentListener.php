<?php
declare(strict_types=1);

namespace App\Listeners\Orders;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Events\Order\OrderCreated;
use App\Domain\Services\PaymentSystems\PsMir\Client\PsMirClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class RequestPaymentListener implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'created-orders-queue';

    public bool $afterCommit = true;

    public function __construct(private readonly PsMirClient $paymentSystem)
    {

    }

    public function shouldQueue(OrderCreated $event): bool
    {
        return (bool) config('marketplace.payment_systems.ps_mir.auto_charge');
    }

    public function handle(OrderCreated $event): void
    {
        $order = Order::findById($event->orderId);

        if (!$order) {
            Log::warning('payment.charge.order_not_found', ['order_id' => $event->orderId]);

            return;
        }

        if ($order->status !== OrderStatusEnum::CREATED) {
            Log::info('payment.charge.skipped', [
                'order_id'     => $order->id,
                'order_status' => $order->status->value,
            ]);

            return;
        }

        $this->paymentSystem->charge(
            $order->id,
            (string) $order->total_amount,
            $order->currency
        );
    }
}
