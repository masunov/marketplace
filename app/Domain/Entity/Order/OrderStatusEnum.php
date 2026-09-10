<?php
declare(strict_types=1);

namespace App\Domain\Entity\Order;

use App\Domain\Events\Order\OrderCreated;
use App\Domain\Events\Order\OrderDelivered;
use App\Domain\Events\Order\OrderPartiallyDelivered;
use App\Domain\Events\Order\OrderRefunded;
use App\Domain\Events\Order\OrderDelivering;
use App\Domain\Events\Order\OrderDeliveryFailed;
use App\Domain\Events\Order\OrderOutOfStock;
use App\Domain\Events\Order\OrderPaid;
use App\Domain\Events\Order\OrderPaymentFailed;
use App\Domain\Events\Order\OrderStatusEvent;

enum OrderStatusEnum: string
{

    case CREATED = 'created';
    case PAID = 'paid';
    case DELIVERING = 'delivering';
    case DELIVERED = 'delivered';

    case PARTIALLY_DELIVERED = 'partially_delivered';

    case REFUNDED = 'refunded';
    case PAYMENT_FAILED = 'payment_failed';
    case OUT_OF_STOCK = 'out_of_stock';
    case DELIVERY_FAILED = 'delivery_failed';

    /** @return class-string<OrderStatusEvent> */
    public function event(): string
    {
        return match ($this) {
            self::CREATED         => OrderCreated::class,
            self::PAID            => OrderPaid::class,
            self::DELIVERING      => OrderDelivering::class,
            self::DELIVERED           => OrderDelivered::class,
            self::PARTIALLY_DELIVERED => OrderPartiallyDelivered::class,
            self::REFUNDED            => OrderRefunded::class,
            self::PAYMENT_FAILED  => OrderPaymentFailed::class,
            self::OUT_OF_STOCK    => OrderOutOfStock::class,
            self::DELIVERY_FAILED => OrderDeliveryFailed::class,
        };
    }

    public function isTerminalForDelivery(): bool
    {
        return in_array(
            $this,
            [self::DELIVERED, self::PAYMENT_FAILED, self::OUT_OF_STOCK, self::DELIVERY_FAILED],
            true
        );
    }

    public function isFinal(): bool
    {
        return in_array(
            $this,
            [self::DELIVERED, self::PARTIALLY_DELIVERED, self::REFUNDED, self::PAYMENT_FAILED],
            true
        );
    }

}
