<?php
declare(strict_types=1);

namespace App\Domain\Entity\Order;

use App\Domain\Services\ProductVendors\VendorIssueOutcomeEnum;

enum OrderProcessingResultEnum: string
{

    case IN_FLIGHT = 'in_flight';
    case SUCCESS = 'success';
    case TIMEOUT = 'timeout';
    case ERROR = 'error';
    case OUT_OF_STOCK = 'out_of_stock';

    public static function fromOutcome(VendorIssueOutcomeEnum $outcome): self
    {
        return match ($outcome) {
            VendorIssueOutcomeEnum::SUCCESS      => self::SUCCESS,
            VendorIssueOutcomeEnum::TIMEOUT      => self::TIMEOUT,
            VendorIssueOutcomeEnum::ERROR        => self::ERROR,
            VendorIssueOutcomeEnum::OUT_OF_STOCK => self::OUT_OF_STOCK,
        };
    }

    public function isAttemptFinished(): bool
    {
        return $this !== self::IN_FLIGHT;
    }

    public function allowsSameVendorRetry(): bool
    {
        return $this === self::TIMEOUT || $this === self::IN_FLIGHT;
    }

}
