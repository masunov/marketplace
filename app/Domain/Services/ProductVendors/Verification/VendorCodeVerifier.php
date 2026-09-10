<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors\Verification;

use App\Domain\Services\ProductVendors\IVendorProcessor;
use App\Domain\Services\ProductVendors\VendorIssueOutcomeEnum;
use Illuminate\Support\Facades\Log;

final readonly class VendorCodeVerifier
{

    public function verify(
        IVendorProcessor $processor,
        string $orderItemId,
        string $attemptRequestId,
        string $code
    ): VendorCodeVerdictEnum
    {
        $check = $processor->checkKey($orderItemId, $attemptRequestId);

        $verdict = match (true) {
            $check->isSuccess() && hash_equals((string) $check->code, $code) => VendorCodeVerdictEnum::VERIFIED,
            $check->isSuccess()                                             => VendorCodeVerdictEnum::MISMATCHED,
            $check->outcome === VendorIssueOutcomeEnum::TIMEOUT             => VendorCodeVerdictEnum::UNVERIFIABLE,
            default                                                         => VendorCodeVerdictEnum::NOT_OURS,
        };

        if (!$verdict->isVerified()) {
            Log::warning('order.delivery.code_verification_failed', [
                'order_item_id'      => $orderItemId,
                'vendor'             => $processor->vendor()->value,
                'attempt_request_id' => $attemptRequestId,
                'verdict'            => $verdict->value,
                'returned_code'      => $code,
                'vendor_says'        => $check->code,
            ]);
        }

        return $verdict;
    }

}
