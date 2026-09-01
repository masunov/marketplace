<?php
declare(strict_types=1);

namespace App\Domain\Services\PaymentSystems\PsMir\Dto;

use App\Domain\Entity\Currency\CurrencyEnum;
use App\Domain\Entity\PaymentSystem\PaymentStatusEnum;
use App\Domain\Entity\PaymentSystem\PaymentSystemEnum;
use Carbon\CarbonImmutable;

readonly class PaymentCallbackDto
{

    public function __construct(
        public string            $callbackId,
        public PaymentSystemEnum $paymentSystem,
        public string            $orderId,
        public PaymentStatusEnum $status,
        public string            $amount,
        public CurrencyEnum      $currency,
        public CarbonImmutable   $createdAt,
        public array             $payload,
    )
    {

    }

    public static function fromArray(PaymentSystemEnum $paymentSystem, array $payload): self
    {
        return new self(
            callbackId:    (string) $payload['event_id'],
            paymentSystem: $paymentSystem,
            orderId:       (string) $payload['order_id'],
            status:        PaymentStatusEnum::from($payload['status']),
            amount:        (string) $payload['amount'],
            currency:      CurrencyEnum::from($payload['currency']),
            createdAt:     CarbonImmutable::parse($payload['created_at']),
            payload:       $payload,
        );
    }

}
