<?php
declare(strict_types=1);

namespace App\Jobs\Orders;

use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Order\OrderItemStatusEnum;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Entity\Order\OrderProcessingLog;
use App\Domain\Entity\Order\OrderProcessingResultEnum;
use App\Domain\Entity\ProductVendor\Exceptions\DuplicateVendorCodeException;
use App\Domain\Entity\ProductVendor\Vendor;
use App\Domain\Factories\OrderContent\OrderContentFactory;
use App\Domain\Factories\ProductVendors\ProductVendorProcessorsFactory;
use App\Domain\Services\ProductVendors\Dto\VendorIssueResult;
use App\Domain\Services\ProductVendors\RateLimit\Exceptions\VendorRateLimitedException;
use App\Domain\Services\ProductVendors\RateLimit\VendorRateLimiter;
use App\Domain\Services\ProductVendors\IVendorProcessor;
use App\Domain\Services\ProductVendors\Verification\VendorCodeVerifier;
use App\Domain\Services\ProductVendors\VendorIssueOutcomeEnum;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderItemDeliveringAttemptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    private const array EMPTY_STATS = [
        'attempts'     => 0,
        'unknown'      => 0,
        'failed'       => 0,
        'out_of_stock' => 0,
        'rejected'     => 0,
    ];

    public function __construct(public readonly string $orderItemId)
    {

    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->orderItemId))->releaseAfter(30)->expireAfter(120),
        ];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('marketplace.delivery.deadline_in_minutes'));
    }

    public function handle(
        ProductVendorProcessorsFactory $processors,
        OrderContentFactory $contents,
        VendorCodeVerifier $verifier,
        VendorRateLimiter $limiter
    ): void
    {
        $item = OrderItem::findById($this->orderItemId);

        if (!$item) {
            Log::warning('order.delivery.item_not_found', ['order_item_id' => $this->orderItemId]);

            return;
        }

        if ($item->status !== OrderItemStatusEnum::DELIVERING) {
            Log::info('order.delivery.attempt_skipped', $this->context($item));

            return;
        }

        if ($this->alreadyDelivered($item, $contents)) {
            $this->markDelivered($item);

            return;
        }

        if ($this->deadlineExceeded($item)) {
            Log::error('order.delivery.deadline_exceeded', $this->context($item));
            $this->markFailed($item, 'deadline_exceeded');

            return;
        }

        $stats  = OrderProcessingLog::vendorStatsForItemCycle($item);
        $vendor = $this->resolveVendor($item, $stats);

        if (!$vendor) {
            $this->finishWithoutVendor($item, $stats);

            return;
        }

        $wait = $limiter->secondsUntilAvailable($vendor->name);

        if ($wait > 0) {
            Log::info('order.delivery.rate_limited', $this->context($item, $vendor) + ['retry_after' => $wait]);

            $this->release($wait + random_int(0, 5));

            return;
        }

        try {
            $this->attempt($item, $vendor, $stats, $processors, $contents, $verifier);
        } catch (VendorRateLimitedException $e) {
            Log::info('order.delivery.rate_limited_mid_attempt', $this->context($item, $vendor));

            $this->release($e->retryAfterSeconds);
        }
    }

    private function attempt(
        OrderItem $item,
        Vendor $vendor,
        Collection $stats,
        ProductVendorProcessorsFactory $processors,
        OrderContentFactory $contents,
        VendorCodeVerifier $verifier
    ): void
    {
        $unfinished = OrderProcessingLog::unfinishedAttempt($item, $vendor->name);

        $attemptRequestId = $unfinished?->attempt_request_id ?? OrderProcessingLog::attemptRequestId(
            $item,
            $vendor->name,
            OrderProcessingLog::nextAttemptNumber($item, $vendor->name)
        );

        $log       = $unfinished ?? OrderProcessingLog::startVendorAttempt($item, $vendor->name, $attemptRequestId);
        $processor = $processors->getProcessorByVendorName($vendor->name->value);

        $result = $processor->issueKey($item->id, $item->product->sku, $attemptRequestId);

        Log::info('order.delivery.attempt_finished', $this->context($item, $vendor, $result));

        match ($result->outcome) {
            VendorIssueOutcomeEnum::SUCCESS
                => $this->acceptCode($item, $vendor, $log, $attemptRequestId, $result->code, $processor, $contents, $verifier),
            VendorIssueOutcomeEnum::ERROR
                => $this->resolveClaimedError($item, $vendor, $log, $attemptRequestId, $processor, $contents, $verifier),
            VendorIssueOutcomeEnum::OUT_OF_STOCK
                => $this->finish($item, $log, OrderProcessingResultEnum::OUT_OF_STOCK, $result->reason),
            VendorIssueOutcomeEnum::TIMEOUT
                => $this->finish($item, $log, OrderProcessingResultEnum::TIMEOUT, null, $stats),
        };
    }

    private function resolveClaimedError(
        OrderItem $item,
        Vendor $vendor,
        OrderProcessingLog $log,
        string $attemptRequestId,
        IVendorProcessor $processor,
        OrderContentFactory $contents,
        VendorCodeVerifier $verifier
    ): void
    {
        $check = $processor->checkKey($item->id, $attemptRequestId);

        Log::info('order.delivery.claimed_error_checked', $this->context($item, $vendor, $check));

        match ($check->outcome) {
            VendorIssueOutcomeEnum::SUCCESS
                => $this->acceptCode($item, $vendor, $log, $attemptRequestId, $check->code, $processor, $contents, $verifier),

            VendorIssueOutcomeEnum::ERROR,
            VendorIssueOutcomeEnum::OUT_OF_STOCK
                => $this->finish($item, $log, OrderProcessingResultEnum::ERROR, $check->reason),

            VendorIssueOutcomeEnum::TIMEOUT
                => $this->finish($item, $log, OrderProcessingResultEnum::UNVERIFIED, 'check_unavailable'),
        };
    }

    private function acceptCode(
        OrderItem $item,
        Vendor $vendor,
        OrderProcessingLog $log,
        string $attemptRequestId,
        ?string $code,
        IVendorProcessor $processor,
        OrderContentFactory $contents,
        VendorCodeVerifier $verifier
    ): void
    {
        if ($code === null) {
            $this->finish($item, $log, OrderProcessingResultEnum::UNVERIFIED, 'empty_code');

            return;
        }

        $verdict = $verifier->verify($processor, $item->id, $attemptRequestId, $code);

        if (!$verdict->isVerified()) {
            $this->finish($item, $log, $verdict->toProcessingResult(), $verdict->value);

            return;
        }

        try {
            $this->store($item, $vendor, $attemptRequestId, $code, $contents);
        } catch (DuplicateVendorCodeException $e) {
            Log::error('order.delivery.duplicate_code', $this->context($item, $vendor) + ['code' => $code]);
            $this->finish($item, $log, OrderProcessingResultEnum::DUPLICATE_CODE, $e->getMessage());

            return;
        }

        $log->finishVendorAttempt(OrderProcessingResultEnum::SUCCESS);
        $this->markDelivered($item);
    }

    private function store(
        OrderItem $item,
        Vendor $vendor,
        string $attemptRequestId,
        string $code,
        OrderContentFactory $contents
    ): void
    {
        DB::transaction(function () use ($item, $vendor, $attemptRequestId, $code, $contents): void {
            $contentClass = $contents->contentClassFor($item->product->type);

            $content = $contentClass::issueForItem($item, $vendor->name, $attemptRequestId, $code);

            $item->attachRelatedContent($content);
        });
    }

    private function finish(
        OrderItem $item,
        OrderProcessingLog $log,
        OrderProcessingResultEnum $result,
        ?string $reason = null,
        ?Collection $stats = null
    ): void
    {
        $log->finishVendorAttempt($result, $reason);

        $attempts = $stats ? (int) $stats->sum('attempts') : 1;

        $result->allowsSameVendorRetry()
            ? $this->scheduleRetry($item, $attempts + 1)
            : $this->scheduleNext($item);
    }

    private function resolveVendor(OrderItem $item, Collection $stats): ?Vendor
    {
        foreach ($item->product->vendors as $vendor) {
            $vendorStats = $stats->get($vendor->name->value, self::EMPTY_STATS);

            $budgetLeft = $vendorStats['attempts'] < (int) $vendor->pivot->max_attempts;

            if ($vendorStats['unknown'] > 0) {
                return $budgetLeft ? $vendor : null;
            }

            if ($vendorStats['rejected'] > 0) {
                if ($budgetLeft) {
                    return $vendor;
                }

                continue;
            }

            if ($vendorStats['failed'] > 0) {
                continue;
            }

            return $vendor;
        }

        return null;
    }

    private function finishWithoutVendor(OrderItem $item, Collection $stats): void
    {
        $vendors = $item->product->vendors;

        $unresolved = $vendors->first(
            static fn(Vendor $vendor): bool => ($stats->get($vendor->name->value)['unknown'] ?? 0) > 0
        );

        if ($unresolved) {
            Log::error('order.delivery.timeouts_unresolved', $this->context($item, $unresolved));
            $this->markFailed($item, 'timeouts_unresolved');

            return;
        }

        $allOutOfStock = $vendors->isNotEmpty() && $vendors->every(
            static fn(Vendor $vendor): bool => ($stats->get($vendor->name->value)['out_of_stock'] ?? 0) > 0
        );

        if ($allOutOfStock) {
            Log::warning('order.delivery.all_vendors_out_of_stock', $this->context($item));
            $this->markOutOfStock($item);

            return;
        }

        Log::warning('order.delivery.vendors_exhausted', $this->context($item));
        $this->markFailed($item, 'vendors_exhausted');
    }

    private function alreadyDelivered(OrderItem $item, OrderContentFactory $contents): bool
    {
        $contentClass = $contents->contentClassFor($item->product->type);

        return $contentClass::findByItem($item) !== null;
    }

    private function markDelivered(OrderItem $item): void
    {
        $this->finishItem($item, OrderItemStatusEnum::DELIVERED);
    }

    private function markOutOfStock(OrderItem $item): void
    {
        $this->finishItem($item, OrderItemStatusEnum::OUT_OF_STOCK);
    }

    private function markFailed(OrderItem $item, string $reason): void
    {
        Log::warning('order.delivery.item_failed', $this->context($item) + ['reason' => $reason]);

        $this->finishItem($item, OrderItemStatusEnum::DELIVERY_FAILED);
    }

    private function finishItem(OrderItem $item, OrderItemStatusEnum $status): void
    {
        $item->tryTransitionTo(OrderItemStatusEnum::DELIVERING, $status);

        OrderFinalizeJob::dispatch($item->order_id)->onQueue('order-finalize-queue');
    }

    private function scheduleNext(OrderItem $item): void
    {
        self::dispatch($item->id)->onQueue(self::deliveryQueue());
    }

    private function scheduleRetry(OrderItem $item, int $attempt): void
    {
        self::dispatch($item->id)
            ->onQueue(self::deliveryQueue())
            ->delay($this->retryDelay($attempt));
    }

    public static function deliveryQueue(): string
    {
        return (string) config('marketplace.delivery.queue');
    }

    private function retryDelay(int $attempt): int
    {
        $base   = (int) config('marketplace.delivery.base_delay_in_seconds');
        $max    = (int) config('marketplace.delivery.max_delay_in_seconds');
        $jitter = (int) config('marketplace.delivery.jitter_in_seconds');

        return min($base * $attempt, $max) + random_int(0, $jitter);
    }

    private function deadlineExceeded(OrderItem $item): bool
    {
        if (!$item->delivery_cycle_started_at) {
            return false;
        }

        $deadline = (int) config('marketplace.delivery.deadline_in_minutes');

        return $item->delivery_cycle_started_at->addMinutes($deadline)->isPast();
    }

    /** @return array<string, mixed> */
    private function context(OrderItem $item, ?Vendor $vendor = null, ?VendorIssueResult $result = null): array
    {
        return [
            'order_id'      => $item->order_id,
            'order_item_id' => $item->id,
            'item_status'   => $item->status->value,
            'request_id'    => $item->request_id,
            'vendor'        => $vendor?->name->value,
            'outcome'       => $result?->outcome->value,
            'reason'        => $result?->reason,
        ];
    }
}
