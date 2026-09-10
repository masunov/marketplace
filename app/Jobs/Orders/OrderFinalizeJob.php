<?php
declare(strict_types=1);

namespace App\Jobs\Orders;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Order\OrderItemStatusEnum;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Entity\Order\OrderTransaction;
use App\Domain\Services\PaymentSystems\PsMir\Client\Exceptions\PaymentSystemUnavailableException;
use App\Domain\Services\PaymentSystems\PsMir\Client\PsMirClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderFinalizeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

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

    public function handle(PsMirClient $refunds): void
    {
        $order = Order::findById($this->orderId);

        if (!$order) {
            Log::warning('order.finalize.order_not_found', ['order_id' => $this->orderId]);

            return;
        }

        if ($order->status->isFinal()) {
            return;
        }

        if (!OrderItem::allDeliveryFinished($order->id)) {
            Log::info('order.finalize.items_still_running', ['order_id' => $order->id]);

            return;
        }

        $pending = $this->refundUndelivered($order, $refunds);

        if ($pending > 0) {
            Log::warning('order.finalize.refunds_pending', [
                'order_id' => $order->id,
                'pending'  => $pending,
            ]);

            return;
        }

        $this->close($order);
    }

    /** @return int сколько позиций осталось без возврата */
    private function refundUndelivered(Order $order, PsMirClient $refunds): int
    {
        $pending = 0;

        foreach (OrderItem::awaitingRefund($order->id) as $item) {
            if (!$this->refundItem($item, $refunds)) {
                $pending++;
            }
        }

        return $pending;
    }

    private function refundItem(OrderItem $item, PsMirClient $refunds): bool
    {
        try {
            $response = $refunds->refund(
                OrderTransaction::refundKey($item),
                (string) $item->price,
                $item->currency
            );
        } catch (PaymentSystemUnavailableException $e) {
            Log::warning('order.finalize.refund_unavailable', [
                'order_item_id' => $item->id,
                'reason'        => $e->getMessage(),
            ]);

            return false;
        }

        if (!$response->isSuccess()) {
            OrderTransaction::refundRejected($item, (string) $response->reason);

            Log::error('order.finalize.refund_rejected', [
                'order_item_id' => $item->id,
                'reason'        => $response->reason,
            ]);

            return false;
        }

        DB::transaction(function () use ($item, $response): void {
            OrderTransaction::refund($item, now(), $response->refundId);

            $item->tryTransitionTo($item->status, OrderItemStatusEnum::REFUNDED);
        });

        Log::info('order.finalize.refunded', [
            'order_item_id' => $item->id,
            'refund_id'     => $response->refundId,
            'amount'        => $item->price,
        ]);

        return true;
    }

    private function close(Order $order): void
    {
        $items     = OrderItem::forOrder($order->id);
        $delivered = $items->filter(
            static fn(OrderItem $item): bool => $item->status === OrderItemStatusEnum::DELIVERED
        )->count();

        $target = match (true) {
            $delivered === $items->count() => OrderStatusEnum::DELIVERED,
            $delivered === 0               => OrderStatusEnum::REFUNDED,
            default                        => OrderStatusEnum::PARTIALLY_DELIVERED,
        };

        $from = $order->status;

        if (!$order->tryTransitionTo($from, $target)) {
            Log::info('order.finalize.transition_skipped', [
                'order_id'     => $order->id,
                'order_status' => $order->status->value,
            ]);

            return;
        }

        event(new ($target->event())($order->id, $from));

        Log::info('order.finalize.closed', [
            'order_id'  => $order->id,
            'status'    => $target->value,
            'delivered' => $delivered,
            'items'     => $items->count(),
            'money'     => OrderTransaction::balance($order->id),
        ]);
    }
}
