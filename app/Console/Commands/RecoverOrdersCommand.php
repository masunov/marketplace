<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Entity\ProductVendor\VendorKey;
use App\Domain\Events\Order\OrderDelivered;
use App\Domain\Events\Order\OrderDelivering;
use App\Jobs\Orders\OrderKeyDeliveringAttemptJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecoverOrdersCommand extends Command
{
    protected $signature = 'marketplace:recover-orders
        {--statuses= : какие статусы восстанавливать, через запятую}
        {--stale-minutes=15 : сколько заказ должен провисеть, чтобы считаться зависшим}
        {--limit=200 : максимум заказов за прогон}
        {--dry-run : только показать, ничего не менять}';

    protected $description = 'Безопасно доводит зависшие и восстановимые заказы до выдачи';

    /**
     * @return array<int, OrderStatusEnum>
     */
    private const array RECOVERABLE = [
        OrderStatusEnum::OUT_OF_STOCK,
        OrderStatusEnum::DELIVERY_FAILED,
        OrderStatusEnum::PAID,
        OrderStatusEnum::DELIVERING,
    ];

    public function handle(): int
    {
        $statuses = $this->statuses();
        $dryRun   = (bool) $this->option('dry-run');
        $stale    = now()->subMinutes((int) $this->option('stale-minutes'));

        $orders = Order::recoverable($statuses, $stale, (int) $this->option('limit'));

        if ($orders->isEmpty()) {
            $this->info('нечего восстанавливать');

            return self::SUCCESS;
        }

        $this->line("кандидатов: {$orders->count()}" . ($dryRun ? ' (dry-run)' : ''));

        $restarted = 0;
        $repaired  = 0;
        $skipped   = 0;

        foreach ($orders as $order) {
            $from = $order->status;

            if (VendorKey::findByOrder($order)) {
                $repaired += $this->finishAlreadyIssued($order, $dryRun);

                continue;
            }

            if ($dryRun) {
                $this->line("  {$order->getKey()} {$from->value} -> delivering");
                $restarted++;

                continue;
            }

            if (!$order->tryTransitionTo($from, OrderStatusEnum::DELIVERING)) {
                $skipped++;

                continue;
            }

            Log::info('order.recovery.restarted', [
                'order_id'        => $order->getKey(),
                'previous_status' => $from->value,
            ]);

            OrderDelivering::dispatch($order->getKey(), $from, 'recovery');
            OrderKeyDeliveringAttemptJob::dispatch($order->getKey());

            $restarted++;
        }

        $this->line("перезапущено: {$restarted}");
        $this->line("починено статусов: {$repaired}");
        $this->line("пропущено (статус изменился): {$skipped}");

        return self::SUCCESS;
    }

    private function finishAlreadyIssued(Order $order, bool $dryRun): int
    {
        if ($dryRun) {
            $this->line("  {$order->getKey()} {$order->status->value} -> delivered (ключ уже выдан)");

            return 1;
        }

        $from = $order->status;

        if (!$order->tryTransitionTo($from, OrderStatusEnum::DELIVERED)) {
            return 0;
        }

        Log::warning('order.recovery.key_without_delivered_status', [
            'order_id'        => $order->getKey(),
            'previous_status' => $from->value,
        ]);

        OrderDelivered::dispatch($order->getKey(), $from, 'recovery');

        return 1;
    }

    /**
     * @return array<int, OrderStatusEnum>
     */
    private function statuses(): array
    {
        $option = $this->option('statuses');

        if (!$option) {
            return self::RECOVERABLE;
        }

        return array_map(
            static fn(string $status): OrderStatusEnum => OrderStatusEnum::from(trim($status)),
            explode(',', (string) $option)
        );
    }
}
