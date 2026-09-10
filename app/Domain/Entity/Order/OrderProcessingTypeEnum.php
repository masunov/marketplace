<?php
declare(strict_types=1);

namespace App\Domain\Entity\Order;

enum OrderProcessingTypeEnum: string
{

    case ORDER_STATUS_CHANGED = 'order_status_changed';
    case ORDER_ITEM_STATUS_CHANGED = 'order_item_status_changed';
    case VENDOR_ATTEMPT_STARTED = 'vendor_attempt_started';
    case VENDOR_ATTEMPT_FINISHED = 'vendor_attempt_finished';

}
