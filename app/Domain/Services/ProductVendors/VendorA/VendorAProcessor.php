<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors\VendorA;

use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Domain\Services\ProductVendors\Dto\VendorIssueResult;
use App\Domain\Services\ProductVendors\IVendorProcessor;
use App\Domain\Services\ProductVendors\VendorA\Client\Dto\IssueKeyResponse;
use App\Domain\Services\ProductVendors\VendorA\Client\Exceptions\TimeoutException;
use App\Domain\Services\ProductVendors\VendorA\Client\VendorAClient;

readonly class VendorAProcessor implements IVendorProcessor
{

    public function __construct(private VendorAClient $client)
    {

    }

    public function vendor(): VendorEnum
    {
        return VendorEnum::VENDOR_A;
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
