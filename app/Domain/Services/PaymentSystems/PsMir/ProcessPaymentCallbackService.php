<?php
declare(strict_types=1);

namespace App\Domain\Services\PaymentSystems\PsMir;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Entity\PaymentSystem\PaymentCallbackLog;
use App\Domain\Entity\PaymentSystem\PaymentStatusEnum;
use App\Domain\Events\Order\OrderStatusEvent;
use App\Domain\Services\PaymentSystems\PsMir\Dto\PaymentCallbackDto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPaymentCallbackService
{

    public function __construct(private readonly int $lockTtlSeconds)
    {

    }

    public function execute(PaymentCallbackDto $dto): void
    {
        $log = $this->storeCallback($dto);

        if (!$log) {
            Log::info('payment.callback.duplicate', $this->context($dto));

            return;
        }

        $lock = Cache::lock($this->lockKey($dto->orderId), $this->lockTtlSeconds);

        if (!$lock->get()) {
            Log::info('payment.callback.locked', $this->context($dto));

            return;
        }

        try {
            $event = DB::transaction(fn(): ?OrderStatusEvent => $this->apply($log, $dto));
        } finally {
            $lock->release();
        }

        if ($event) {
            event($event);
        }
    }

    private function storeCallback(PaymentCallbackDto $dto): ?PaymentCallbackLog
    {
        try {
            $callback                 = new PaymentCallbackLog();
            $callback->ps_callback_id = $dto->callbackId;
            $callback->payment_system = $dto->paymentSystem;
            $callback->order_id       = $dto->orderId;
            $callback->payment_status = $dto->status;
            $callback->amount         = $dto->amount;
            $callback->currency       = $dto->currency->value;
            $callback->ps_created_at  = $dto->createdAt;
            $callback->payload        = $dto->payload;
            $callback->save();

            return $callback;
        } catch (UniqueConstraintViolationException) {
            $existing = PaymentCallbackLog::findExistingCallback($dto->callbackId, $dto->paymentSystem);

            return $existing?->processed_at === null ? $existing : null;
        }
    }

    private function apply(PaymentCallbackLog $log, PaymentCallbackDto $dto): ?OrderStatusEvent
    {
        $order = Order::findById($dto->orderId);

        if (!$order) {
            Log::warning('payment.callback.order_not_found', $this->context($dto));

            return null;
        }

        if ($order->status->isFinal()) {
            Log::info('payment.callback.order_already_final', $this->context($dto, $order));
            $log->markProcessed();

            return null;
        }

        if (!$this->amountMatches($order, $dto)) {
            Log::error('payment.callback.amount_mismatch', $this->context($dto, $order));
            $log->markProcessed();

            return null;
        }

        $target = $dto->status === PaymentStatusEnum::PAID
            ? OrderStatusEnum::PAID
            : OrderStatusEnum::PAYMENT_FAILED;

        $transitioned = $order->tryTransitionTo(OrderStatusEnum::CREATED, $target);
        $log->markProcessed();

        if (!$transitioned) {
            Log::info('payment.callback.transition_skipped', $this->context($dto, $order));

            return null;
        }

        Log::info('payment.callback.applied', $this->context($dto, $order));

        return new ($target->event())($order->getKey(), OrderStatusEnum::CREATED, null, $dto->createdAt);
    }

    private function amountMatches(Order $order, PaymentCallbackDto $dto): bool
    {
        return $order->currency === $dto->currency
               && bccomp((string)$order->price, $dto->amount, 2) === 0;
    }

    private function lockKey(string $orderId): string
    {
        return "order:{$orderId}:payment";
    }

    private function context(PaymentCallbackDto $dto, ?Order $order = null): array
    {
        return [
            'event_id'       => $dto->callbackId,
            'payment_system' => $dto->paymentSystem->value,
            'order_id'       => $dto->orderId,
            'payment_status' => $dto->status->value,
            'amount'         => $dto->amount,
            'currency'       => $dto->currency->value,
            'order_status'   => $order?->status->value,
        ];
    }

}
