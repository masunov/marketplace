<?php
declare(strict_types=1);

namespace App\Domain\Entity\PaymentSystem;

enum PaymentStatusEnum: string
{
    case PAID = 'paid';
    case FAILED = 'failed';

}
