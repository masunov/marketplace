<?php
declare(strict_types=1);

namespace App\Jobs\PaymentSystems;

use App\Domain\Entity\Currency\CurrencyEnum;
use App\Domain\Entity\PaymentSystem\PaymentStatusEnum;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PsMirCallbackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public readonly string $eventId;

    public function __construct(
        public readonly string $orderId,
        public readonly PaymentStatusEnum $status,
        public readonly string $amount,
        public readonly CurrencyEnum $currency,
    )
    {
        $this->eventId = (string) Str::uuid7();
    }

    public function handle(): void
    {
        $url = (string) config('marketplace.payment_systems.ps_mir.callback_url');

        $response = Http::acceptJson()
                        ->timeout(10)
                        ->post($url, [
                            'event_id'   => $this->eventId,
                            'order_id'   => $this->orderId,
                            'status'     => $this->status->value,
                            'amount'     => $this->amount,
                            'currency'   => $this->currency->value,
                            'created_at' => now()->toIso8601ZuluString(),
                        ]);

        Log::info('payment.callback.delivered', [
            'event_id' => $this->eventId,
            'order_id' => $this->orderId,
            'status'   => $this->status->value,
            'http'     => $response->status(),
        ]);

        $response->throwIfServerError();
    }
}
