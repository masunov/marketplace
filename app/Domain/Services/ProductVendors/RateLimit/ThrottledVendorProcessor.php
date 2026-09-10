<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors\RateLimit;

use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Domain\Services\ProductVendors\Dto\VendorIssueResult;
use App\Domain\Services\ProductVendors\IVendorProcessor;

readonly class ThrottledVendorProcessor implements IVendorProcessor
{

    public function __construct(
        private IVendorProcessor $processor,
        private VendorRateLimiter $limiter,
    )
    {

    }

    public function vendor(): VendorEnum
    {
        return $this->processor->vendor();
    }

    public function issueKey(string $orderId, string $sku, string $requestId): VendorIssueResult
    {
        $this->limiter->acquire($this->vendor());

        return $this->processor->issueKey($orderId, $sku, $requestId);
    }

    public function checkKey(string $orderId, string $requestId): VendorIssueResult
    {
        $this->limiter->acquire($this->vendor());

        return $this->processor->checkKey($orderId, $requestId);
    }

}
