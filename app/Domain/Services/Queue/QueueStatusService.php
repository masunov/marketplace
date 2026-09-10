<?php
declare(strict_types=1);

namespace App\Domain\Services\Queue;

use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Order\OrderItemStatusEnum;
use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Domain\Services\ProductVendors\RateLimit\VendorRateLimiter;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\RedisQueue;

readonly class QueueStatusService
{

    public function __construct(
        private VendorRateLimiter $limiter,
        private QueueFactory $queue,
    )
    {

    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $items = OrderItem::countByStatus();

        $waiting   = ($items[OrderItemStatusEnum::PENDING->value] ?? 0)
                     + ($items[OrderItemStatusEnum::DELIVERING->value] ?? 0);
        $delivered = $items[OrderItemStatusEnum::DELIVERED->value] ?? 0;

        $vendors = $this->vendors();

        return [
            'generated_at' => now()->toIso8601String(),
            'items'        => [
                'waiting'   => $waiting,
                'delivered' => $delivered,
                'refunded'  => $items[OrderItemStatusEnum::REFUNDED->value] ?? 0,
                'by_status' => $items,
            ],
            'vendors'      => $vendors,
            'queues'       => $this->queues(),
            'eta_seconds'  => $this->eta($waiting, $this->capacity($vendors)),
        ];
    }

    /** @return array<string, array<string, int>> */
    private function vendors(): array
    {
        $vendors = [];

        foreach (VendorEnum::cases() as $vendor) {
            $vendors[$vendor->value] = $this->limiter->snapshot($vendor);
        }

        return $vendors;
    }

    /** @return array<string, array<string, int>> */
    private function queues(): array
    {
        /** @var RedisQueue $connection */
        $connection = $this->queue->connection('redis');

        $queues = [];

        foreach ($this->queueNames() as $name) {
            $ready    = (int) $connection->pendingSize($name);
            $delayed  = (int) $connection->delayedSize($name);
            $reserved = (int) $connection->reservedSize($name);

            $queues[$name] = [
                'ready'    => $ready,
                'delayed'  => $delayed,
                'reserved' => $reserved,
                'total'    => $ready + $delayed + $reserved,
            ];
        }

        return $queues;
    }

    /** @return array<int, string> */
    private function queueNames(): array
    {
        $environment = (string) (config('horizon.env') ?? config('app.env'));

        $supervisors = array_replace_recursive(
            (array) config('horizon.defaults', []),
            (array) config("horizon.environments.{$environment}", [])
        );

        return collect($supervisors)
            ->pluck('queue')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function eta(int $waiting, int $capacity): ?int
    {
        if ($waiting === 0) {
            return 0;
        }

        if ($capacity <= 0) {
            return null;
        }

        $requestsPerItem = 2;

        return (int) ceil($waiting * $requestsPerItem / $capacity * 60);
    }

    /** @param  array<string, array<string, int>>  $vendors */
    private function capacity(array $vendors): int
    {
        return array_sum(array_column($vendors, 'rpm_limit'));
    }

}
