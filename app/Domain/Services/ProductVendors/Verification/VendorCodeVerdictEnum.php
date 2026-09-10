<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors\Verification;

use App\Domain\Entity\Order\OrderProcessingResultEnum;

enum VendorCodeVerdictEnum: string
{

    case VERIFIED = 'verified';

    case NOT_OURS = 'not_ours';

    case MISMATCHED = 'mismatched';

    case UNVERIFIABLE = 'unverifiable';

    public function isVerified(): bool
    {
        return $this === self::VERIFIED;
    }

    public function toProcessingResult(): OrderProcessingResultEnum
    {
        return match ($this) {
            self::VERIFIED     => OrderProcessingResultEnum::SUCCESS,
            self::NOT_OURS,
            self::MISMATCHED   => OrderProcessingResultEnum::FOREIGN_CODE,
            self::UNVERIFIABLE => OrderProcessingResultEnum::UNVERIFIED,
        };
    }

}
