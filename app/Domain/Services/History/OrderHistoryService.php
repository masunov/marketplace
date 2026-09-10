<?php
declare(strict_types=1);

namespace App\Domain\Services\History;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Order\OrderItemStatusEnum;
use App\Domain\Entity\Order\OrderProcessingLog;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Entity\Order\OrderTransaction;
use Illuminate\Support\Collection;

readonly class OrderHistoryService
{

    public const string UNKNOWN_STATUS = 'unknown';

    private const int MONEY_SCALE = 2;

    /** @return array<string, mixed>|null */
    public function stateAt(Order $order, \DateTimeInterface $moment): ?array
    {
        if ($order->created_at > $moment) {
            return null;
        }

        $orderId = $order->id;

        $events = OrderProcessingLog::statusHistory($orderId, $moment);
        $items  = OrderItem::forOrder($orderId);

        $complete = $this->historyIsComplete($events);

        return [
            'order_id'         => $orderId,
            'as_of'            => $moment->format(\DATE_ATOM),
            'status'           => $this->orderStatusAt($events),
            'items'            => $this->itemStatesAt($items, $events, $complete),
            'money'            => $this->moneyAt($orderId, $moment),
            'history_complete' => $complete,
        ];
    }

    /** @return array<string, mixed> */
    public function periodTotals(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $charged  = OrderTransaction::sumChargedBetween($from, $to);
        $refunded = OrderTransaction::sumRefundedBetween($from, $to);

        return [
            'from'           => $from->format(\DATE_ATOM),
            'to'             => $to->format(\DATE_ATOM),
            'charged_total'  => (float) $charged,
            'refunded_total' => (float) $refunded,
            'net_total'      => (float) bcsub($charged, $refunded, self::MONEY_SCALE),
            'orders_touched' => OrderTransaction::orderCountBetween($from, $to),
        ];
    }

    /** @param  Collection<int, OrderProcessingLog>  $events */
    private function historyIsComplete(Collection $events): bool
    {
        $hasItemEvents = $events->contains(
            static fn(OrderProcessingLog $log): bool => $log->item_status !== null
        );

        if ($hasItemEvents) {
            return true;
        }

        return !$events->contains(
            static fn(OrderProcessingLog $log): bool => $log->order_status !== null
                && !in_array(
                    $log->order_status,
                    [OrderStatusEnum::CREATED, OrderStatusEnum::PAID, OrderStatusEnum::PAYMENT_FAILED],
                    true
                )
        );
    }

    /** @param  Collection<int, OrderProcessingLog>  $events */
    private function orderStatusAt(Collection $events): string
    {
        $last = $events->last(
            static fn(OrderProcessingLog $log): bool => $log->order_status !== null
        );

        return $last?->order_status->value ?? 'created';
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, OrderItem>  $items
     * @param  Collection<int, OrderProcessingLog>  $events
     * @return array<int, array<string, mixed>>
     */
    private function itemStatesAt(iterable $items, Collection $events, bool $historyIsComplete): array
    {
        $states = [];

        foreach ($items as $item) {
            $last = $events->last(
                static fn(OrderProcessingLog $log): bool => $log->order_item_id === $item->id
                                                            && $log->item_status !== null
            );

            $states[] = [
                'id'     => $item->id,
                'price'  => $item->price,
                'status' => match (true) {
                    $last !== null      => $last->item_status->value,
                    $historyIsComplete  => OrderItemStatusEnum::PENDING->value,
                    default             => self::UNKNOWN_STATUS,
                },
            ];
        }

        return $states;
    }

    /** @return array<string, mixed> */
    private function moneyAt(string $orderId, \DateTimeInterface $moment): array
    {
        $charged  = OrderTransaction::sumChargedUntil($orderId, $moment);
        $refunded = OrderTransaction::sumRefundedUntil($orderId, $moment);

        return [
            'charged_total'  => (float) $charged,
            'refunded_total' => (float) $refunded,
            'held_total'     => (float) bcsub($charged, $refunded, self::MONEY_SCALE),
        ];
    }

}
