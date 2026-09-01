<?php
declare(strict_types=1);

namespace App\Domain\Factories\ProductVendors;

use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Domain\Factories\ProductVendors\Exceptions\UndefinedProductVendorException;
use App\Domain\Services\ProductVendors\IVendorProcessor;
use App\Domain\Services\ProductVendors\VendorA\VendorAProcessor;
use App\Domain\Services\ProductVendors\VendorB\VendorBProcessor;

readonly class ProductVendorProcessorsFactory
{

    public function __construct(
        private VendorAProcessor $vendorAProcessor,
        private VendorBProcessor $vendorBProcessor,
    ) {

    }


    public function getProcessorByVendorName(string $vendorName): IVendorProcessor
    {
        return match ($vendorName) {
            VendorEnum::VENDOR_A->value => $this->vendorAProcessor,
            VendorEnum::VENDOR_B->value => $this->vendorBProcessor,
            default => throw new UndefinedProductVendorException("Vendor not supported: $vendorName exception"),
        };

    }
}
