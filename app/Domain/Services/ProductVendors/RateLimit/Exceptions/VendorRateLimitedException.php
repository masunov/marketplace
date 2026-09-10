<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors\RateLimit\Exceptions;

use App\Domain\Entity\ProductVendor\VendorEnum;

class VendorRateLimitedException extends \RuntimeException
{

    public function __construct(
        public readonly VendorEnum $vendor,
        public readonly int $retryAfterSeconds,
    )
    {
        parent::__construct(
            "Vendor {$vendor->value} rate limit reached, retry after {$retryAfterSeconds}s."
        );
    }

}
