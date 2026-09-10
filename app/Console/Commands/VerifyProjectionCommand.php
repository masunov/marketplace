<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Order\OrderTransaction;
use App\Domain\Services\History\OrderHistoryService;
use Illuminate\Console\Command;

class VerifyProjectionCommand extends Command
{
    protected $signature = 'marketplace:verify-projection
        {--limit=500 : сколько заказов проверить}
        {--json : машинный вывод}';

    protected $description = 'Сверяет текущее состояние заказов со сверткой истории';

    public function handle(OrderHistoryService $history): int
    {
        $orders     = Order::recentlyTouched((int) $this->option('limit'));
        $mismatches = [];

        foreach ($orders as $order) {
            $mismatches = [...$mismatches, ...$this->compare($order, $history)];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'checked'    => $orders->count(),
                'mismatches' => $mismatches,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $mismatches === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line("проверено заказов: {$orders->count()}");

        if ($mismatches === []) {
            $this->info('свертка истории совпадает с текущим состоянием');

            return self::SUCCESS;
        }

        $this->table(['заказ', 'что', 'в истории', 'в таблице'], array_map(
            static fn(array $row): array => [
                $row['order_id'],
                $row['subject'],
                $row['history'],
                $row['projection'],
            ],
            array_slice($mismatches, 0, 20)
        ));

        $this->error('расхождений: ' . count($mismatches));

        return self::FAILURE;
    }

    /** @return array<int, array<string, string>> */
    private function compare(Order $order, OrderHistoryService $history): array
    {
        $state = $history->stateAt($order, now());

        if (!$state) {
            return [];
        }

        $found = [];

        if ($state['status'] !== $order->status->value) {
            $found[] = [
                'order_id'   => $order->id,
                'subject'    => 'статус заказа',
                'history'    => $state['status'],
                'projection' => $order->status->value,
            ];
        }

        $actual = OrderItem::forOrder($order->id)->keyBy('id');

        foreach ($state['items'] as $item) {
            $current = $actual->get($item['id']);

            if ($current && $item['status'] !== $current->status->value) {
                $found[] = [
                    'order_id'   => $order->id,
                    'subject'    => 'позиция ' . substr((string) $item['id'], 0, 8),
                    'history'    => $item['status'],
                    'projection' => $current->status->value,
                ];
            }
        }

        $money = $state['money'];
        $held  = bcsub(
            OrderTransaction::sumCharged($order->id),
            OrderTransaction::sumRefunded($order->id),
            2
        );

        $fromHistory = number_format((float) $money['held_total'], 2, '.', '');

        if (bccomp($fromHistory, $held, 2) !== 0) {
            $found[] = [
                'order_id'   => $order->id,
                'subject'    => 'деньги',
                'history'    => $fromHistory,
                'projection' => $held,
            ];
        }

        return $found;
    }
}
