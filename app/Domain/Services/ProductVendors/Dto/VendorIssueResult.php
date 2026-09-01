<?php
declare(strict_types=1);

namespace App\Domain\Services\ProductVendors\Dto;

use App\Domain\Services\ProductVendors\VendorIssueOutcomeEnum;

readonly class VendorIssueResult
{

    private function __construct(
        public VendorIssueOutcomeEnum $outcome,
        public ?string                $code = null,
        public ?string                $reason = null,
    )
    {

    }

    public static function success(string $code): self
    {
        return new self(outcome: VendorIssueOutcomeEnum::SUCCESS, code: $code);
    }

    public static function timeout(): self
    {
        return new self(outcome: VendorIssueOutcomeEnum::TIMEOUT);
    }

    public static function error(?string $reason = null): self
    {
        return new self(outcome: VendorIssueOutcomeEnum::ERROR, reason: $reason);
    }

    public static function outOfStock(?string $reason = null): self
    {
        return new self(outcome: VendorIssueOutcomeEnum::OUT_OF_STOCK, reason: $reason);
    }

    public function isSuccess(): bool
    {
        return $this->outcome === VendorIssueOutcomeEnum::SUCCESS;
    }

}
