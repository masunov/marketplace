<?php
declare(strict_types=1);

namespace App\Domain\Services\PaymentSystems\PsMir\Client\Dto;

readonly class ChargeResponse
{

    private function __construct(
        public string $paymentId,
    )
    {

    }

    public static function accepted(string $paymentId): self
    {
        return new self(paymentId: $paymentId);
    }

}
