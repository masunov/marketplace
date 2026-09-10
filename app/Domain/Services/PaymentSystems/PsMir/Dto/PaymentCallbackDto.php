<?php
declare(strict_types=1);

namespace App\Domain\Services\PaymentSystems\PsMir\Dto;

use App\Domain\Entity\Currency\CurrencyEnum;
use App\Domain\Entity\PaymentSystem\PaymentCallbackLog;
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
            createdAt:     CarbonImmutable::parse($payload['created_at'])
                ->setTimezone(config('app.timezone')),
            payload:       $payload,
        );
    }

    public static function fromLog(PaymentCallbackLog $log): self
    {
        return new self(
            callbackId:    $log->ps_callback_id,
            paymentSystem: $log->payment_system,
            orderId:       $log->order_id,
            status:        $log->payment_status,
            amount:        (string) $log->amount,
            currency:      $log->currency,
            createdAt:     $log->ps_created_at,
            payload:       $log->payload ?? [],
        );
    }

}
