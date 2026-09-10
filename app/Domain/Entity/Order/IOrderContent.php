<?php
declare(strict_types=1);

namespace App\Domain\Entity\Order;

use App\Domain\Entity\Product\ProductTypeEnum;

interface IOrderContent
{

    public static function morphAlias(): string;

    /** @return array<int, ProductTypeEnum> */
    public static function supportedProductTypes(): array;

    public function contentValue(): string;

}
