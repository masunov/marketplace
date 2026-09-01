<?php
declare(strict_types=1);

namespace App\Domain\Events\Order;

use App\Domain\Entity\Order\OrderStatusEnum;

final class OrderDeliveryFailed extends OrderStatusEvent
{

    public static function status(): OrderStatusEnum
    {
        return OrderStatusEnum::DELIVERY_FAILED;
    }

}
