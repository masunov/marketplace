<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors\VendorB;

use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Domain\Services\ProductVendors\Dto\VendorIssueResult;
use App\Domain\Services\ProductVendors\IVendorProcessor;
use App\Domain\Services\ProductVendors\VendorB\Client\Dto\IssueKeyResponse;
use App\Domain\Services\ProductVendors\VendorB\Client\Exceptions\TimeoutException;
use App\Domain\Services\ProductVendors\VendorB\Client\VendorBClient;

readonly class VendorBProcessor implements IVendorProcessor
{

    public function __construct(private VendorBClient $client)
    {

    }

    public function vendor(): VendorEnum
    {
        return VendorEnum::VENDOR_B;
    }

    public function issueKey(string $orderId, string $sku, string $requestId): VendorIssueResult
    {
        try {
            $response = $this->client->issueKey($orderId, $sku, $requestId);
        } catch (TimeoutException) {
            return VendorIssueResult::timeout();
        }

        return match ($response->status) {
            IssueKeyResponse::SUCCESS => VendorIssueResult::success($response->code),
            IssueKeyResponse::ERROR   => VendorIssueResult::outOfStock($response->reason),
            IssueKeyResponse::FAIL    => VendorIssueResult::error($response->reason),
        };
    }

    public function checkKey(string $orderId, string $requestId): VendorIssueResult
    {
        try {
            $response = $this->client->checkKey($orderId, $requestId);
        } catch (TimeoutException) {
            return VendorIssueResult::timeout();
        }

        return match ($response->status) {
            IssueKeyResponse::SUCCESS => VendorIssueResult::success($response->code),
            IssueKeyResponse::ERROR   => VendorIssueResult::error($response->reason),
            IssueKeyResponse::FAIL    => VendorIssueResult::timeout(),
        };
    }

}
