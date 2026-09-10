<?php
declare(strict_types=1);

namespace App\Domain\Entity\Order;

enum OrderTransactionTypeEnum: string
{

    case CHARGE = 'charge';
    case REFUND = 'refund';

}
