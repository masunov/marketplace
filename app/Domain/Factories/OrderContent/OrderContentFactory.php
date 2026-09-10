<?php
declare(strict_types=1);

namespace App\Domain\Factories\OrderContent;

use App\Domain\Entity\Order\IOrderContent;
use App\Domain\Entity\Product\ProductTypeEnum;
use App\Domain\Entity\ProductVendor\VendorGiftCard;
use App\Domain\Entity\ProductVendor\VendorKey;
use App\Domain\Factories\OrderContent\Exceptions\UndefinedOrderContentException;

final readonly class OrderContentFactory
{

    /** @return array<int, class-string<IOrderContent>> */
    private const array CONTENTS = [
        VendorKey::class,
        VendorGiftCard::class,
    ];

    /** @return class-string<IOrderContent> */
    public function contentClassFor(ProductTypeEnum $productType): string
    {
        foreach (self::CONTENTS as $content) {
            if (in_array($productType, $content::supportedProductTypes(), true)) {
                return $content;
            }
        }

        throw new UndefinedOrderContentException(
            "No order content model supports product type {$productType->value}."
        );
    }

    /** @return array<string, class-string<IOrderContent>> */
    public static function morphMap(): array
    {
        $map = [];

        foreach (self::CONTENTS as $content) {
            $map[$content::morphAlias()] = $content;
        }

        return $map;
    }

}
