<?php
declare(strict_types=1);

namespace App\Jobs\Orders;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderProcessingLog;
use App\Domain\Entity\Order\OrderProcessingResultEnum;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Entity\ProductVendor\Vendor;
use App\Domain\Entity\ProductVendor\VendorKey;
use App\Domain\Events\Order\OrderDelivered;
use App\Domain\Events\Order\OrderDeliveryFailed;
use App\Domain\Events\Order\OrderOutOfStock;
use App\Domain\Factories\ProductVendors\ProductVendorProcessorsFactory;
use App\Domain\Services\ProductVendors\Dto\VendorIssueResult;
use App\Domain\Services\ProductVendors\VendorIssueOutcomeEnum;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderKeyDeliveringAttemptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 1;

    private const array EMPTY_STATS = ['attempts' => 0, 'unknown' => 0, 'failed' => 0, 'out_of_stock' => 0];

    public function __construct(public readonly string $orderId)
    {

    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->orderId))->releaseAfter(30)->expireAfter(120),
        ];
    }

    public function handle(ProductVendorProcessorsFactory $processorsFactory): void
    {
        $order = Order::findById($this->orderId);

        if (!$order) {
            Log::warning('order.delivery.order_not_found', ['order_id' => $this->orderId]);

            return;
        }

        if ($order->status !== OrderStatusEnum::DELIVERING) {
            Log::info('order.delivery.attempt_skipped', $this->context($order));

            return;
        }

        $issuedKey = VendorKey::findByOrder($order);

        if ($issuedKey) {
            $this->markDelivered($order);

            return;
        }

        if ($this->deadlineExceeded($order)) {
            Log::error('order.delivery.deadline_exceeded', $this->context($order));
            $this->markDeliveryFailed($order, 'deadline_exceeded');

            return;
        }

        $vendorStats = OrderProcessingLog::vendorStatsForCycle($order);

        $vendor = $this->resolveVendor($order, $vendorStats);

        if (!$vendor) {
            $this->finishWithoutVendor($order, $vendorStats, $processorsFactory);

            return;
        }

        $log = OrderProcessingLog::startAttempt($order, $vendor);

        $processors = $processorsFactory->getProcessorByVendorName($vendor->name->value);

        $result = $processors->issueKey($order->id, $order->product->sku, $order->request_id);

        $log->finishAttempt(OrderProcessingResultEnum::fromOutcome($result->outcome));

        Log::info('order.delivery.attempt_finished', $this->context($order, $vendor, $result));

        match ($result->outcome) {
            VendorIssueOutcomeEnum::SUCCESS      => $this->deliver($order, $vendor, $result),
            VendorIssueOutcomeEnum::TIMEOUT      => $this->scheduleRetry($order, $this->totalAttempts($vendorStats) + 1),
            VendorIssueOutcomeEnum::ERROR,
            VendorIssueOutcomeEnum::OUT_OF_STOCK => $this->scheduleFallback($order),
        };
    }

    private function resolveVendor(Order $order, Collection $vendorStats): ?Vendor
    {
        foreach ($order->product->vendors as $vendor) {
            $stats = $vendorStats->get($vendor->name->value, self::EMPTY_STATS);

            if ($stats['failed'] > 0) {
                continue;
            }

            if ($stats['unknown'] > 0) {
                return $stats['unknown'] < (int) $vendor->pivot->max_attempts ? $vendor : null;
            }

            return $vendor;
        }

        return null;
    }

    private function finishWithoutVendor(
        Order $order,
        Collection $vendorStats,
        ProductVendorProcessorsFactory $processorsFactory
    ): void
    {
        $vendors = $order->product->vendors;

        $timedOutVendor = $vendors->first(
            static function (Vendor $vendor) use ($vendorStats): bool {
                $stats = $vendorStats->get($vendor->name->value, self::EMPTY_STATS);

                return $stats['unknown'] > 0 && $stats['failed'] === 0;
            }
        );

        if ($timedOutVendor) {
            $this->resolveTimeouts($order, $timedOutVendor, $processorsFactory);

            return;
        }

        $allOutOfStock = $vendors->isNotEmpty() && $vendors->every(
            static fn(Vendor $vendor): bool => ($vendorStats->get($vendor->name->value)['out_of_stock'] ?? 0) > 0
        );

        if ($allOutOfStock) {
            Log::warning('order.delivery.all_vendors_out_of_stock', $this->context($order));
            $this->markOutOfStock($order, 'all_vendors_out_of_stock');

            return;
        }

        Log::warning('order.delivery.vendors_exhausted', $this->context($order));
        $this->markDeliveryFailed($order, 'vendors_exhausted');
    }

    private function totalAttempts(Collection $vendorStats): int
    {
        return (int) $vendorStats->sum('attempts');
    }

    private function resolveTimeouts(
        Order $order,
        Vendor $vendor,
        ProductVendorProcessorsFactory $processorsFactory
    ): void
    {
        $log = OrderProcessingLog::startAttempt($order, $vendor);

        $result = $processorsFactory
            ->getProcessorByVendorName($vendor->name->value)
            ->checkKey($order->getKey(), $order->request_id);

        $log->finishAttempt(OrderProcessingResultEnum::fromOutcome($result->outcome));

        Log::info('order.delivery.timeouts_check_finished', $this->context($order, $vendor, $result));

        match ($result->outcome) {
            VendorIssueOutcomeEnum::SUCCESS => $this->deliver($order, $vendor, $result),
            VendorIssueOutcomeEnum::ERROR,
            VendorIssueOutcomeEnum::OUT_OF_STOCK => $this->scheduleFallback($order),
            VendorIssueOutcomeEnum::TIMEOUT => $this->markDeliveryFailed($order, 'timeouts_unresolved'),
        };
    }

    private function scheduleFallback(Order $order): void
    {
        self::dispatch($order->getKey())->onQueue('order-key-delivering-attempt-queue');
    }



    private function deliver(Order $order, Vendor $vendor, VendorIssueResult $result): void
    {
        DB::transaction(function () use ($order, $vendor, $result): void {
            $key = VendorKey::issueForOrder($order, $vendor->name, $order->request_id, $result->code);

            $order->attachRelatedContent($key);
        });

        $this->markDelivered($order);
    }

    private function markDelivered(Order $order): void
    {
        if ($order->tryTransitionTo(OrderStatusEnum::DELIVERING, OrderStatusEnum::DELIVERED)) {
            OrderDelivered::dispatch($order->getKey(), OrderStatusEnum::DELIVERING);
        }
    }

    private function markOutOfStock(Order $order, ?string $reason): void
    {
        if ($order->tryTransitionTo(OrderStatusEnum::DELIVERING, OrderStatusEnum::OUT_OF_STOCK)) {
            OrderOutOfStock::dispatch($order->getKey(), OrderStatusEnum::DELIVERING, $reason);
        }
    }

    private function markDeliveryFailed(Order $order, string $reason): void
    {
        if ($order->tryTransitionTo(OrderStatusEnum::DELIVERING, OrderStatusEnum::DELIVERY_FAILED)) {
            OrderDeliveryFailed::dispatch($order->getKey(), OrderStatusEnum::DELIVERING, $reason);
        }
    }

    private function scheduleRetry(Order $order, int $attempt): void
    {
        self::dispatch($order->getKey())
            ->onQueue('order-key-delivering-attempt-queue')
            ->delay($this->retryDelay($attempt));
    }

    private function retryDelay(int $attempt): int
    {
        $base   = (int) config('marketplace.delivery.base_delay_in_seconds');
        $max    = (int) config('marketplace.delivery.max_delay_in_seconds');
        $jitter = (int) config('marketplace.delivery.jitter_in_seconds');

        return min($base * $attempt, $max) + random_int(0, $jitter);
    }

    private function deadlineExceeded(Order $order): bool
    {
        if (!$order->delivery_cycle_started_at) {
            return false;
        }

        $deadline = (int) config('marketplace.delivery.deadline_in_minutes');

        return $order->delivery_cycle_started_at->addMinutes($deadline)->isPast();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Order $order, ?Vendor $vendor = null, ?VendorIssueResult $result = null): array
    {
        return [
            'order_id'     => $order->getKey(),
            'order_status' => $order->status->value,
            'request_id'   => $order->request_id,
            'vendor'       => $vendor?->name->value,
            'outcome'      => $result?->outcome->value,
            'reason'       => $result?->reason,
        ];
    }
}
