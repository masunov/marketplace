<?php
declare(strict_types=1);

namespace App\Domain\Events\Order;

use App\Domain\Entity\Order\OrderStatusEnum;

final class OrderPartiallyDelivered extends OrderStatusEvent
{

    public static function status(): OrderStatusEnum
    {
        return OrderStatusEnum::PARTIALLY_DELIVERED;
    }

}
