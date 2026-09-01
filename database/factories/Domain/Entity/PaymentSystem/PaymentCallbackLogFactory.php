<?php

namespace Database\Factories\Domain\Entity\PaymentSystem;

use App\Domain\Entity\Currency\CurrencyEnum;
use App\Domain\Entity\PaymentSystem\PaymentCallbackLog;
use App\Domain\Entity\PaymentSystem\PaymentStatusEnum;
use App\Domain\Entity\PaymentSystem\PaymentSystemEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentCallbackLog>
 */
class PaymentCallbackLogFactory extends Factory
{
    protected $model = PaymentCallbackLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $callbackId = 'evt_' . Str::lower(Str::random(12));
        $orderId    = Str::uuid()->toString();
        $amount     = $this->faker->numberBetween(299, 3490);
        $status     = PaymentStatusEnum::PAID;
        $createdAt  = now()->toImmutable();

        return [
            'ps_callback_id' => $callbackId,
            'payment_system' => PaymentSystemEnum::PS_MIR,
            'order_id'       => $orderId,
            'payment_status' => $status,
            'amount'         => $amount,
            'currency'       => CurrencyEnum::RUB,
            'ps_created_at'  => $createdAt,
            'processed_at'   => null,
            'payload'        => [
                'event_id'   => $callbackId,
                'order_id'   => $orderId,
                'status'     => $status->value,
                'amount'     => $amount,
                'currency'   => CurrencyEnum::RUB->value,
                'created_at' => $createdAt->toIso8601ZuluString(),
            ],
        ];
    }

    public function failed(): self
    {
        return $this->state(fn() => ['payment_status' => PaymentStatusEnum::FAILED]);
    }

    public function processed(): self
    {
        return $this->state(fn() => ['processed_at' => now()]);
    }
}
