<?php
declare(strict_types=1);

namespace App\Domain\Entity\Order;

use App\Domain\Events\Order\OrderCreated;
use App\Domain\Events\Order\OrderDelivered;
use App\Domain\Events\Order\OrderDelivering;
use App\Domain\Events\Order\OrderDeliveryFailed;
use App\Domain\Events\Order\OrderOutOfStock;
use App\Domain\Events\Order\OrderPaid;
use App\Domain\Events\Order\OrderPaymentFailed;
use App\Domain\Events\Order\OrderStatusEvent;

enum OrderTypeEnum: string
{

    case TOPUP = 'topup';
    case KEY = 'key';
    case SUBSCRIPTION = 'subscription';
    case GIFTCARD = 'giftcard';


}
