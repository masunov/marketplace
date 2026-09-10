<?php
declare(strict_types=1);

namespace App\Domain\Entity\Order;

enum OrderItemStatusEnum: string
{

    case PENDING = 'pending';
    case DELIVERING = 'delivering';
    case DELIVERED = 'delivered';
    case OUT_OF_STOCK = 'out_of_stock';
    case DELIVERY_FAILED = 'delivery_failed';
    case REFUNDED = 'refunded';

    public function isDeliveryFinished(): bool
    {
        return in_array(
            $this,
            [self::DELIVERED, self::OUT_OF_STOCK, self::DELIVERY_FAILED, self::REFUNDED],
            true
        );
    }

    public function needsRefund(): bool
    {
        return in_array($this, [self::OUT_OF_STOCK, self::DELIVERY_FAILED], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::DELIVERED, self::REFUNDED], true);
    }

}
