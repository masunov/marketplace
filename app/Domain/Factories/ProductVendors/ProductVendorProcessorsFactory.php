<?php
declare(strict_types=1);

namespace App\Domain\Factories\ProductVendors;

use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Domain\Factories\ProductVendors\Exceptions\UndefinedProductVendorException;
use App\Domain\Services\ProductVendors\IVendorProcessor;

readonly class ProductVendorProcessorsFactory
{

    public function __construct(
        private IVendorProcessor $vendorAProcessor,
        private IVendorProcessor $vendorBProcessor,
        private IVendorProcessor $vendorCProcessor,
    ) {

    }

    public function getProcessorByVendorName(string $vendorName): IVendorProcessor
    {
        return match ($vendorName) {
            VendorEnum::VENDOR_A->value => $this->vendorAProcessor,
            VendorEnum::VENDOR_B->value => $this->vendorBProcessor,
            VendorEnum::VENDOR_C->value => $this->vendorCProcessor,
            default => throw new UndefinedProductVendorException("Vendor not supported: $vendorName exception"),
        };

    }
}
