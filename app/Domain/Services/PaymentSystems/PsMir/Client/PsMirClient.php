<?php
declare(strict_types=1);

namespace App\Domain\Services\PaymentSystems\PsMir\Client;

use App\Domain\Entity\Currency\CurrencyEnum;
use App\Domain\Entity\PaymentSystem\PaymentStatusEnum;
use App\Domain\Services\PaymentSystems\PsMir\Client\Dto\ChargeResponse;
use App\Domain\Services\PaymentSystems\PsMir\Client\Dto\RefundResponse;
use App\Jobs\PaymentSystems\PsMirCallbackJob;
use App\Domain\Services\PaymentSystems\PsMir\Client\Exceptions\PaymentSystemUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

readonly class PsMirClient
{

    private const int DEDUPE_TTL_IN_SECONDS = 86400;

    public function __construct(
        private int $totalChances,
        private int $rejectChance,
        private int $unavailableChance,
        private int $chargeFailChance,
        private int $chargeLostChance,
        private int $callbackMinDelay,
        private int $callbackMaxDelay,
    )
    {

    }

    public function charge(string $orderId, string $amount, CurrencyEnum $currency): ChargeResponse
    {
        $paymentId = 'pay_' . Str::lower(Str::random(16));

        if ($this->rolls($this->chargeLostChance)) {
            Log::warning('payment.charge.callback_lost', [
                'order_id'   => $orderId,
                'payment_id' => $paymentId,
            ]);

            return ChargeResponse::accepted($paymentId);
        }

        $status = $this->rolls($this->chargeFailChance)
            ? PaymentStatusEnum::FAILED
            : PaymentStatusEnum::PAID;

        Log::info('payment.charge.accepted', [
            'order_id'   => $orderId,
            'payment_id' => $paymentId,
            'will_be'    => $status->value,
        ]);

        PsMirCallbackJob::dispatch($orderId, $status, $amount, $currency)
                        ->delay(random_int($this->callbackMinDelay, $this->callbackMaxDelay))
                        ->onQueue('ps-mir-callback-queue');

        return ChargeResponse::accepted($paymentId);
    }

    public function refund(
        string $idempotencyKey,
        string $amount,
        CurrencyEnum $currency
    ): RefundResponse
    {
        $known = Cache::get($this->cacheKey($idempotencyKey));

        if ($known) {
            Log::info('payment.refund.idempotent_hit', [
                'idempotency_key' => $idempotencyKey,
                'refund_id'       => $known,
            ]);

            return RefundResponse::buildForSuccess($known);
        }

        if ($this->rolls($this->unavailableChance)) {
            Log::error('payment.refund.unavailable', ['idempotency_key' => $idempotencyKey]);

            throw new PaymentSystemUnavailableException('PS Mir is unavailable.');
        }

        if ($this->rolls($this->rejectChance)) {
            Log::error('payment.refund.rejected', ['idempotency_key' => $idempotencyKey]);

            return RefundResponse::buildForRejection('refund_declined');
        }

        $refundId = 'rf_' . Str::lower(Str::random(16));

        if (!Cache::add($this->cacheKey($idempotencyKey), $refundId, self::DEDUPE_TTL_IN_SECONDS)) {
            $winner = (string) Cache::get($this->cacheKey($idempotencyKey));

            Log::info('payment.refund.idempotent_race', [
                'idempotency_key' => $idempotencyKey,
                'refund_id'       => $winner,
            ]);

            return RefundResponse::buildForSuccess($winner);
        }

        Log::info('payment.refund.succeeded', [
            'idempotency_key' => $idempotencyKey,
            'refund_id'       => $refundId,
            'amount'          => $amount,
            'currency'        => $currency->value,
        ]);

        return RefundResponse::buildForSuccess($refundId);
    }

    private function rolls(int $chance): bool
    {
        return random_int(1, $this->totalChances) <= $chance;
    }

    private function cacheKey(string $idempotencyKey): string
    {
        return "ps_mir:refund:{$idempotencyKey}";
    }

}
