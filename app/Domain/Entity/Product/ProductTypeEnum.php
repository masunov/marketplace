<?php
declare(strict_types=1);

namespace App\Domain\Entity\Product;

enum ProductTypeEnum: string
{

    case TOPUP = 'topup';
    case KEY = 'key';
    case SUBSCRIPTION = 'subscription';
    case GIFTCARD = 'giftcard';

}
