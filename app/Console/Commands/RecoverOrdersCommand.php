<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Order\OrderItemStatusEnum;
use App\Domain\Factories\OrderContent\OrderContentFactory;
use App\Jobs\Orders\OrderFinalizeJob;
use App\Jobs\Orders\OrderItemDeliveringAttemptJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecoverOrdersCommand extends Command
{
    protected $signature = 'marketplace:recover-orders
        {--statuses= : какие статусы восстанавливать, через запятую}
        {--stale-minutes=15 : сколько позиция должна провисеть в выдаче, чтобы считаться зависшей}
        {--limit=200 : максимум позиций за прогон}
        {--dry-run : только показать, ничего не менять}';

    protected $description = 'Безопасно доводит зависшие и восстановимые позиции заказов до выдачи';

    /** @return array<int, OrderItemStatusEnum> */
    private const array RECOVERABLE = [
        OrderItemStatusEnum::OUT_OF_STOCK,
        OrderItemStatusEnum::DELIVERY_FAILED,
        OrderItemStatusEnum::PENDING,
        OrderItemStatusEnum::DELIVERING,
    ];

    public function handle(OrderContentFactory $contents): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stale  = now()->subMinutes((int) $this->option('stale-minutes'));

        $items = OrderItem::recoverable($this->statuses(), $stale, (int) $this->option('limit'));

        if ($items->isEmpty()) {
            $this->info('нечего восстанавливать');

            return self::SUCCESS;
        }

        $this->line("кандидатов: {$items->count()}" . ($dryRun ? ' (dry-run)' : ''));

        $restarted = 0;
        $repaired  = 0;
        $skipped   = 0;

        foreach ($items as $item) {
            if ($this->hasContent($item, $contents)) {
                $repaired += $this->finishAlreadyIssued($item, $dryRun);

                continue;
            }

            $restarted += $this->restart($item, $dryRun, $skipped);
        }

        $this->finalizeStalled();

        $this->line("перезапущено: {$restarted}");
        $this->line("починено статусов: {$repaired}");
        $this->line("пропущено (статус изменился): {$skipped}");

        return self::SUCCESS;
    }

    private function finalizeStalled(): void
    {
        $orders = Order::stalledBeforeFinalize((int) $this->option('limit'));

        if ($orders->isEmpty()) {
            return;
        }

        $this->line("к закрытию: {$orders->count()}");

        if ($this->option('dry-run')) {
            return;
        }

        foreach ($orders as $order) {
            OrderFinalizeJob::dispatch($order->id)->onQueue('order-finalize-queue');
        }
    }

    private function restart(OrderItem $item, bool $dryRun, int &$skipped): int
    {
        $from = $item->status;

        if ($dryRun) {
            $this->line("  {$item->id} {$from->value} -> delivering");

            return 1;
        }

        if (!$item->tryTransitionTo($from, OrderItemStatusEnum::DELIVERING)) {
            $skipped++;

            return 0;
        }

        Log::info('order.recovery.item_restarted', [
            'order_id'        => $item->order_id,
            'order_item_id'   => $item->id,
            'previous_status' => $from->value,
        ]);

        OrderItemDeliveringAttemptJob::dispatch($item->id)
                                     ->onQueue(OrderItemDeliveringAttemptJob::queueFor($item));

        return 1;
    }

    private function finishAlreadyIssued(OrderItem $item, bool $dryRun): int
    {
        if ($dryRun) {
            $this->line("  {$item->id} {$item->status->value} -> delivered (контент уже выдан)");

            return 1;
        }

        $from = $item->status;

        if (!$item->tryTransitionTo($from, OrderItemStatusEnum::DELIVERED)) {
            return 0;
        }

        Log::warning('order.recovery.content_without_delivered_status', [
            'order_id'        => $item->order_id,
            'order_item_id'   => $item->id,
            'previous_status' => $from->value,
        ]);

        return 1;
    }

    private function hasContent(OrderItem $item, OrderContentFactory $contents): bool
    {
        return $contents->contentClassFor($item->product->type)::findByItem($item) !== null;
    }

    /** @return array<int, OrderItemStatusEnum> */
    private function statuses(): array
    {
        $option = $this->option('statuses');

        if (!$option) {
            return self::RECOVERABLE;
        }

        return array_map(
            static fn(string $status): OrderItemStatusEnum => OrderItemStatusEnum::from(trim($status)),
            explode(',', (string) $option)
        );
    }
}
