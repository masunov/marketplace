<?php
declare(strict_types=1);

namespace App\Domain\Entity\Currency;

enum CurrencyEnum: string
{

    case RUB = 'RUB';
    case KZT = 'KZT';
    case USD = 'USD';

}
