<?php
declare(strict_types=1);

namespace App\Domain\Entity\Order;

enum OrderProcessingResultEnum: string
{

    case IN_FLIGHT = 'in_flight';
    case SUCCESS = 'success';
    case TIMEOUT = 'timeout';
    case ERROR = 'error';
    case OUT_OF_STOCK = 'out_of_stock';

    case FOREIGN_CODE = 'foreign_code';

    case DUPLICATE_CODE = 'duplicate_code';

    case UNVERIFIED = 'unverified';

    public function allowsSameVendorRetry(): bool
    {
        return in_array($this, [self::TIMEOUT, self::IN_FLIGHT, self::UNVERIFIED], true);
    }

}
