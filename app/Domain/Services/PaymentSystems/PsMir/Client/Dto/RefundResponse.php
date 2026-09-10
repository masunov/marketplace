<?php
declare(strict_types=1);

namespace App\Domain\Services\PaymentSystems\PsMir\Client\Dto;

readonly class RefundResponse
{

    public const string SUCCESS = 'success';
    public const string REJECTED = 'rejected';

    private function __construct(
        public string  $status,
        public ?string $refundId = null,
        public ?string $reason = null,
    )
    {

    }

    public static function buildForSuccess(string $refundId): self
    {
        return new self(status: self::SUCCESS, refundId: $refundId);
    }

    public static function buildForRejection(string $reason): self
    {
        return new self(status: self::REJECTED, reason: $reason);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }

}
