<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors\VendorA\Client;

use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Domain\Services\ProductVendors\VendorA\Client\Dto\IssueKeyResponse;
use App\Domain\Services\ProductVendors\VendorA\Client\Exceptions\TimeoutException;
use App\Models\ExternalVendorErrorReasonEnum;
use App\Models\ExternalVendorKey;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Lottery;

readonly class VendorAClient
{

    public function __construct(
        private int $totalChances,
        private int $backendErrorChance,
        private int $timeoutErrorChance
    )
    {

    }

    public function issueKey(string $orderId, string $sku, string $requestId): IssueKeyResponse
    {

        if (Lottery::odds($this->backendErrorChance, $this->totalChances)->choose()) {
            Log::error(
                'Vendor A server error while issuing key',
                [
                    'order_id'   => $orderId,
                    'sku'        => $sku,
                    'request_id' => $requestId,
                    'vendor'     => VendorEnum::VENDOR_A->value,
                    'reason'     => 'Server error'
                ]
            );
            return IssueKeyResponse::buildForFail($requestId, 'Server error');
        }


        $key = ExternalVendorKey::issueOrReturnExisting(VendorEnum::VENDOR_A, $sku, $requestId);


        if ($key) {

            if (Lottery::odds($this->timeoutErrorChance, $this->totalChances - $this->backendErrorChance)->choose()) {
                Log::error(
                    'Vendor A timeout while issuing key',
                    [
                        'order_id'   => $orderId,
                        'sku'        => $sku,
                        'request_id' => $requestId,
                        'vendor'     => VendorEnum::VENDOR_A->value,
                        'reason'     => 'Timeout'
                    ]
                );

                sleep(mt_rand(5, 15));

                throw new TimeoutException('Timeout from vendor A in issueKey request exception');
            }

            $key->markAsIssued();

            return IssueKeyResponse::buildForSuccess(
                $requestId,
                $key->key
            );

        }

        Log::info(
            'Vendor A out_of_stock while issuing key',
            [
                'order_id'   => $orderId,
                'sku'        => $sku,
                'request_id' => $requestId,
                'vendor'     => VendorEnum::VENDOR_A->value,
                'reason'     => 'out_of_stock'
            ]
        );

        return IssueKeyResponse::buildForError($requestId, ExternalVendorErrorReasonEnum::OUT_OF_STOCK->value);

    }

    public function checkKey(string $orderId, string $requestId): IssueKeyResponse
    {

        if (Lottery::odds($this->backendErrorChance, $this->totalChances)->choose()) {
            Log::error(
                'Vendor A server error while checking key',
                [
                    'order_id'   => $orderId,
                    'request_id' => $requestId,
                    'vendor'     => VendorEnum::VENDOR_A->value,
                    'reason'     => 'Server error'
                ]
            );

            return IssueKeyResponse::buildForFail($requestId, 'Server error');
        }

        $key = ExternalVendorKey::findByRequestId(VendorEnum::VENDOR_A, $requestId);

        if ($key) {
            $key->markAsIssued();

            return IssueKeyResponse::buildForSuccess($requestId, $key->key);
        }

        Log::info(
            'Vendor A confirms key was not issued',
            [
                'order_id'   => $orderId,
                'request_id' => $requestId,
                'vendor'     => VendorEnum::VENDOR_A->value,
                'reason'     => 'not_issued'
            ]
        );

        return IssueKeyResponse::buildForError($requestId, 'not_issued');
    }

}
