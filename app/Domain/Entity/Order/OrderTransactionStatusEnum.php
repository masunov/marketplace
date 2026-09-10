<?php
declare(strict_types=1);

namespace App\Domain\Entity\Order;

enum OrderTransactionStatusEnum: string
{

    case SUCCEEDED = 'succeeded';

    case FAILED = 'failed';

}
