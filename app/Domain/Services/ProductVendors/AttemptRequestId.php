<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors;

use App\Domain\Entity\ProductVendor\VendorEnum;
use Ramsey\Uuid\Uuid;

final readonly class AttemptRequestId
{

    private const string NAMESPACE = 'a3f2c1d0-5b6e-4c7a-9e8d-1f2a3b4c5d6e';

    public static function for(string $itemRequestId, VendorEnum $vendor, int $attemptNumber): string
    {
        return Uuid::uuid5(
            self::NAMESPACE,
            "{$itemRequestId}:{$vendor->value}:{$attemptNumber}"
        )->toString();
    }

}
