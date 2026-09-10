<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Order\OrderItemStatusEnum;
use App\Domain\Entity\ProductVendor\VendorEnum;
use App\Domain\Services\Orders\Create\OrderCreateService;
use App\Jobs\Orders\OrderItemDeliveringAttemptJob;
use App\Domain\Services\ProductVendors\RateLimit\VendorRateLimiter;
use App\Console\Commands\Concerns\PaysOrders;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class BurstTestCommand extends Command
{
    use PaysOrders;

    protected $signature = 'marketplace:burst-test
        {--orders=120 : сколько заказов создать залпом}
        {--items=2 : позиций в заказе}
        {--rpm=30 : лимит запросов в минуту на каждого поставщика}
        {--drain-rounds=20 : сколько раз опросить состояние}
        {--round-seconds=10 : пауза между опросами, секунд}';

    protected $description = 'Проводит всплеск заказов через лимит поставщика и проверяет, что ничего не потеряно';

    /** @return array<int, string> */
    private const array QUEUES = [
        'created-orders-queue',
        'default',
        'ps-mir-callback-queue',
        'order-finalize-queue',
        'delivery-paid',
        'order-key-delivering-attempt-queue',
    ];

    /** @return array<int, string> */
    private const array SKUS = [
        'KEY-GTA5',
        'KEY-CS2-PRIME',
        'GIFT-PSN-1000',
        'GIFT-APPSTORE-1000',
    ];

    private \DateTimeImmutable $startedAt;

    public function handle(OrderCreateService $service): int
    {
        $this->prepare();

        $limiter = app(VendorRateLimiter::class);

        if (!$this->workerIsRunning()) {
            $this->error('очередь никто не разбирает — поднимите воркер: docker compose up -d queue');

            return self::FAILURE;
        }

        $orders = $this->createBurst($service);

        // Автосписание выключено на время прогона — платим сами, с заведомым исходом.
        $this->payOrders($orders);

        $this->line("создано заказов: {$orders->count()} залпом");
        $this->line(sprintf('лимит: %d запросов в минуту на поставщика', (int) $this->option('rpm')));
        $this->newLine();

        $this->drain($orders);

        try {
            return $this->report($orders->all(), $limiter);
        } finally {
            $this->restoreLimits($limiter);
        }
    }

    private function prepare(): void
    {
        $rpm = (int) $this->option('rpm');

        config([
            'marketplace.vendors.vendor_a.rpm'                   => $rpm,
            'marketplace.vendors.vendor_b.rpm'                   => $rpm,
            'marketplace.vendors.vendor_c.rpm'                   => $rpm,
            'marketplace.delivery.base_delay_in_seconds'         => 1,
            'marketplace.delivery.max_delay_in_seconds'          => 3,
            'marketplace.delivery.jitter_in_seconds'             => 0,
            'marketplace.payment_systems.ps_mir.auto_charge'     => false,
        ]);

        app()->forgetInstance(VendorRateLimiter::class);

        $this->startedAt = now()->toImmutable();

        $limiter = app(VendorRateLimiter::class);

        foreach (VendorEnum::cases() as $vendor) {
            $limiter->overrideLimit($vendor, $rpm, 3600);
        }

        foreach (self::QUEUES as $queue) {
            Artisan::call('queue:clear', ['--queue' => $queue, '--force' => true]);
        }

        Artisan::call('queue:flush');
    }

    /** @return Collection<int, string> */
    private function createBurst(OrderCreateService $service): Collection
    {
        $items = (int) $this->option('items');

        return collect(range(1, (int) $this->option('orders')))->map(
            function (int $n) use ($service, $items): string {
                $skus = [];

                for ($i = 0; $i < $items; $i++) {
                    $skus[] = self::SKUS[($n + $i) % count(self::SKUS)];
                }

                return $service->execute($skus)->id;
            }
        );
    }

    /** @param  Collection<int, string>  $orders */
    private function drain(Collection $orders): void
    {
        $rounds = (int) $this->option('drain-rounds');

        for ($round = 1; $round <= $rounds; $round++) {
            sleep((int) $this->option('round-seconds'));

            $pending = OrderItem::query()
                                ->whereIn('order_id', $orders->all())
                                ->whereNotIn('status', $this->finishedItemStatuses())
                                ->count();

            $this->progress($round, $pending);

            if ($pending === 0) {
                return;
            }

        }

        $this->warn('очереди не добиты до конца');
    }

    private function restoreLimits(VendorRateLimiter $limiter): void
    {
        foreach (VendorEnum::cases() as $vendor) {
            $limiter->dropOverride($vendor);
        }

        $this->line('лимиты возвращены к настройкам из конфига');
    }

    private function workerIsRunning(): bool
    {
        $before = $this->ready('default');

        if ($before === 0) {
            return true;
        }

        sleep(3);

        return $this->ready('default') < $before;
    }

    private function progress(int $round, int $pending): void
    {
        $this->line(sprintf(
            '  раунд %-4d осталось %-5d очереди: default %-4d первая попытка %-4d повторы %-4d отложено %d',
            $round,
            $pending,
            $this->ready('default'),
            $this->ready('order-key-delivering-attempt-queue'),
            $this->ready(OrderItemDeliveringAttemptJob::deliveryQueue()),
            (int) Redis::zcard('queues:ps-mir-callback-queue:delayed')
        ));
    }

    /** @param  array<int, string>  $ids */
    private function report(array $ids, VendorRateLimiter $limiter): int
    {
        $rpm      = (int) $this->option('rpm');
        $failures = 0;

        $this->newLine();
        $this->line('<comment>запросы к поставщикам по минутам</comment>');

        $worst = 0;

        foreach (VendorEnum::cases() as $vendor) {
            foreach ($this->minuteCounters($vendor, $limiter) as $minute => $count) {
                $over  = $count > $rpm;
                $worst = max($worst, $count);

                $this->line(sprintf(
                    '  %s %-10s %s  %d',
                    $over ? '<error>ПРЕВЫШЕН</error>' : '<info>ок      </info>',
                    $vendor->value,
                    $minute,
                    $count
                ));
            }
        }

        $lost = OrderItem::query()
                         ->whereIn('order_id', $ids)
                         ->whereNotIn('status', $this->finishedItemStatuses())
                         ->count();

        $failedJobs = (int) DB::table('failed_jobs')->count();

        $unsettled = Order::closedWithUnsettledMoney()
                          ->filter(static fn(object $row): bool => in_array($row->order_id, $ids, true))
                          ->count();

        $this->newLine();
        $this->line('<comment>инварианты</comment>');

        $failures += $this->assert('пиковая минута не превышает лимит', $worst <= $rpm, "{$worst} / {$rpm}");
        $failures += $this->assert('позиций не дошло до конца', $lost === 0, (string) $lost);
        $failures += $this->assert('заданий в failed_jobs', $failedJobs === 0, (string) $failedJobs);
        $failures += $this->assert('закрытых заказов с неразнесенными деньгами', $unsettled === 0, (string) $unsettled);

        $this->newLine();

        if ($failures > 0) {
            $this->error("нарушено инвариантов: {$failures}");

            return self::FAILURE;
        }

        $this->info('всплеск пройден: лимит соблюден, ничего не потеряно');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function minuteCounters(VendorEnum $vendor, VendorRateLimiter $limiter): array
    {
        $counters = [];
        $minute   = $this->startedAt;
        $until    = now()->toImmutable();

        while ($minute <= $until) {
            $count = $limiter->requestsInMinute($vendor, $minute);

            if ($count > 0) {
                $counters[$minute->format('H:i')] = $count;
            }

            $minute = $minute->addMinute();
        }

        return $counters;
    }

    private function ready(string $queue): int
    {
        return (int) Redis::llen("queues:{$queue}");
    }

    /** @return array<int, string> */
    private function finishedItemStatuses(): array
    {
        return array_map(
            static fn(OrderItemStatusEnum $status): string => $status->value,
            array_filter(
                OrderItemStatusEnum::cases(),
                static fn(OrderItemStatusEnum $status): bool => $status->isDeliveryFinished()
            )
        );
    }

    private function assert(string $title, bool $ok, string $actual): int
    {
        $this->line(sprintf(
            '  %s %-44s %s',
            $ok ? '<info>OK  </info>' : '<error>FAIL</error>',
            $title,
            $actual
        ));

        return $ok ? 0 : 1;
    }
}
