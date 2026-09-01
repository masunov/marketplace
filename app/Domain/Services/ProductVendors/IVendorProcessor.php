<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors;

use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Domain\Services\ProductVendors\Dto\VendorIssueResult;

interface IVendorProcessor
{

    public function vendor(): VendorEnum;

    public function issueKey(string $orderId, string $sku, string $requestId): VendorIssueResult;

    public function checkKey(string $orderId, string $requestId): VendorIssueResult;

}
