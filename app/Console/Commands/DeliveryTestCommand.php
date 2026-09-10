<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Order\OrderItemStatusEnum;
use App\Domain\Entity\Order\OrderProcessingLog;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Entity\Order\OrderTransaction;
use App\Domain\Entity\Order\OrderTransactionStatusEnum;
use App\Domain\Entity\Order\OrderTransactionTypeEnum;
use App\Domain\Entity\ProductVendor\VendorGiftCard;
use App\Domain\Entity\ProductVendor\VendorKey;
use App\Domain\Services\Orders\Create\OrderCreateService;
use App\Console\Commands\Concerns\PaysOrders;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class DeliveryTestCommand extends Command
{
    use PaysOrders;

    protected $signature = 'marketplace:delivery-test
        {--orders=30 : сколько заказов создать}
        {--items=3 : сколько позиций в заказе}
        {--url= : адрес, на который заглушка ПС шлет вебхук}
        {--fresh : пересобрать базу перед прогоном}
        {--drain-rounds=60 : сколько раз добивать очередь}';

    protected $description = 'Проводит мультитоварные заказы через выдачу и проверяет инварианты';

    private const string DISHONEST_SKU = 'GIFT-APPSTORE-1000';

    /** @return array<int, string> */
    private const array HONEST_SKUS = [
        'KEY-GTA5',
        'KEY-CS2-PRIME',
        'GIFT-PSN-1000',
    ];

    /** @return array<int, string> */
    private const array QUEUES = [
        'default',
        'ps-mir-callback-queue',
        'order-finalize-queue',
        'order-key-delivering-attempt-queue',
    ];

    public function handle(OrderCreateService $orderCreateService): int
    {
        if ($this->option('fresh')) {
            Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
            $this->line('база пересобрана');
        }

        $this->speedUp();

        $orders = $this->createOrders($orderCreateService);

        // Автосписание выключено на время прогона — платим сами, с заведомым исходом.
        $this->payOrders($orders);

        $this->line("создано заказов: {$orders->count()}");

        $this->drain($orders);

        return $this->report($orders->all());
    }

    private function speedUp(): void
    {
        config([
            'marketplace.delivery.base_delay_in_seconds'          => 1,
            'marketplace.delivery.max_delay_in_seconds'           => 2,
            'marketplace.delivery.jitter_in_seconds'              => 0,
            'marketplace.payment_systems.ps_mir.auto_charge'      => false,
        ]);

        if ($this->option('url')) {
            config(['marketplace.payment_systems.ps_mir.callback_url' => $this->option('url')]);
        }
    }

    /** @return Collection<int, string> */
    private function createOrders(OrderCreateService $service): Collection
    {
        $items = (int) $this->option('items');

        return collect(range(1, (int) $this->option('orders')))->map(
            function (int $n) use ($service, $items): string {
                $skus = [self::DISHONEST_SKU];

                for ($i = 1; $i < $items; $i++) {
                    $skus[] = self::HONEST_SKUS[($n + $i) % count(self::HONEST_SKUS)];
                }

                return $service->execute($skus)->id;
            }
        );
    }

    /** @param  Collection<int, string>  $orders */
    private function drain(Collection $orders): void
    {
        for ($round = 1; $round <= (int) $this->option('drain-rounds'); $round++) {
            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--queue'           => implode(',', self::QUEUES),
            ]);

            $pending = Order::query()
                            ->whereIn('id', $orders->all())
                            ->whereNotIn('status', $this->finalStatuses())
                            ->count();

            if ($pending === 0) {
                $this->line("очередь добита за раундов: {$round}");

                return;
            }

            sleep(1);
        }

        $this->warn('очередь не добита до конца — часть заказов еще в работе');
    }

    /** @param  array<int, string>  $ids */
    private function report(array $ids): int
    {
        $this->section('статусы заказов', Order::query()
            ->whereIn('id', $ids)
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')->pluck('c', 'status')->all());

        $this->section('статусы позиций', OrderItem::query()
            ->whereIn('order_id', $ids)
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')->pluck('c', 'status')->all());

        $this->newLine();
        $this->line('<comment>исходы попыток у поставщиков</comment>');

        $attempts = OrderProcessingLog::query()
                                      ->whereIn('order_id', $ids)
                                      ->whereNotNull('vendor')
                                      ->whereNotNull('result')
                                      ->where('result', '!=', 'in_flight')
                                      ->select('vendor', 'result', DB::raw('count(*) as c'))
                                      ->groupBy('vendor', 'result')
                                      ->orderBy('vendor')->orderBy('result')->get();

        foreach ($attempts as $row) {
            $this->line(sprintf('  %-10s %-16s %d', $row->vendor->value, $row->result->value, $row->c));
        }

        return $this->invariants($ids);
    }

    /** @param  array<int, string>  $ids */
    private function invariants(array $ids): int
    {
        $itemIds   = OrderItem::query()->whereIn('order_id', $ids)->pluck('id')->all();
        $keys      = VendorKey::query()->whereIn('order_item_id', $itemIds)->get();
        $cards     = VendorGiftCard::query()->whereIn('order_item_id', $itemIds)->get();
        $codes     = $keys->pluck('key')->merge($cards->pluck('code'));
        $delivered = OrderItem::query()->whereIn('order_id', $ids)
                              ->where('status', OrderItemStatusEnum::DELIVERED->value)->count();
        $refunded  = OrderItem::query()->whereIn('order_id', $ids)
                              ->where('status', OrderItemStatusEnum::REFUNDED->value)->count();

        $refundRows = OrderTransaction::query()
                                      ->whereIn('order_id', $ids)
                                      ->where('type', OrderTransactionTypeEnum::REFUND->value)
                                      ->where('status', OrderTransactionStatusEnum::SUCCEEDED->value)
                                      ->count();

        $unsettled = Order::closedWithUnsettledMoney()
                          ->filter(static fn(object $row): bool => in_array($row->order_id, $ids, true))
                          ->count();

        $this->newLine();
        $this->line('<comment>инварианты</comment>');

        $failures = 0;
        $failures += $this->assert('выдано единиц контента = позиций delivered', $codes->count(), $delivered);
        $failures += $this->assert('один код не выдан дважды', $codes->count() - $codes->unique()->count(), 0);
        $failures += $this->assert('возвратов = позиций refunded', $refundRows, $refunded);
        $failures += $this->assert('закрытых заказов с неразнесенными деньгами', $unsettled, 0);

        $this->newLine();
        $this->money($ids);

        if ($failures > 0) {
            $this->error("нарушено инвариантов: {$failures}");

            return self::FAILURE;
        }

        $this->info('инварианты соблюдены');

        return self::SUCCESS;
    }

    /** @param  array<int, string>  $ids */
    private function money(array $ids): void
    {
        $charged  = (float) OrderTransaction::query()->whereIn('order_id', $ids)
                                            ->where('type', OrderTransactionTypeEnum::CHARGE->value)
                                            ->where('status', OrderTransactionStatusEnum::SUCCEEDED->value)
                                            ->sum('price');
        $refunded = (float) OrderTransaction::query()->whereIn('order_id', $ids)
                                            ->where('type', OrderTransactionTypeEnum::REFUND->value)
                                            ->where('status', OrderTransactionStatusEnum::SUCCEEDED->value)
                                            ->sum('price');
        $kept     = (float) OrderTransaction::query()->whereIn('order_id', $ids)
                                            ->where('type', OrderTransactionTypeEnum::CHARGE->value)
                                            ->where('status', OrderTransactionStatusEnum::SUCCEEDED->value)
                                            ->whereIn('order_item_id', OrderItem::query()
                                                ->whereIn('order_id', $ids)
                                                ->where('status', OrderItemStatusEnum::DELIVERED->value)
                                                ->select('id'))
                                            ->sum('price');

        $this->line('<comment>деньги</comment>');
        $this->line(sprintf('  оплачено   %12s', number_format($charged, 2, '.', ' ')));
        $this->line(sprintf('  выдано     %12s', number_format($kept, 2, '.', ' ')));
        $this->line(sprintf('  возвращено %12s', number_format($refunded, 2, '.', ' ')));
        $this->line(sprintf('  остаток    %12s', number_format($charged - $kept - $refunded, 2, '.', ' ')));
    }

    /** @return array<int, string> */
    private function finalStatuses(): array
    {
        return array_map(
            static fn(OrderStatusEnum $status): string => $status->value,
            array_filter(OrderStatusEnum::cases(), static fn(OrderStatusEnum $s): bool => $s->isFinal())
        );
    }

    /** @param  array<string, int>  $rows */
    private function section(string $title, array $rows): void
    {
        $this->newLine();
        $this->line("<comment>{$title}</comment>");

        foreach ($rows as $key => $count) {
            $this->line(sprintf('  %-22s %d', $key, $count));
        }
    }

    private function assert(string $title, int $actual, int $expected): int
    {
        $ok = $actual === $expected;

        $this->line(sprintf(
            '  %s %-44s ожидалось %-6s получено %s',
            $ok ? '<info>OK  </info>' : '<error>FAIL</error>',
            $title,
            (string) $expected,
            (string) $actual
        ));

        return $ok ? 0 : 1;
    }
}
