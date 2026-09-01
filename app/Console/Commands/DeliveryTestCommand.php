<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderProcessingLog;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Entity\ProductVendor\VendorKey;
use App\Domain\Services\Orders\Create\OrderCreateService;
use Illuminate\Console\Command;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Database\Seeders\ProductSeeder;

class DeliveryTestCommand extends Command
{
    protected $signature = 'marketplace:delivery-test
        {--orders=50 : сколько заказов создать}
        {--url=http://127.0.0.1:8000 : базовый адрес приложения}
        {--fresh : пересобрать базу перед прогоном}
        {--drain-rounds=40 : сколько раз добивать очередь}';

    protected $description = 'Создает N заказов и проводит их через выдачу со случайными отказами и таймаутами поставщиков';

    public function handle(OrderCreateService $orderCreateService): int
    {
        if ($this->option('fresh')) {
            Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
            $this->line('база пересобрана');
        }

        config([
            'marketplace.delivery.base_delay_in_seconds' => 1,
            'marketplace.delivery.max_delay_in_seconds'  => 2,
            'marketplace.delivery.jitter_in_seconds'     => 0,
        ]);

        $count = (int) $this->option('orders');
        $skus  = array_column(ProductSeeder::catalogue(), 'sku');

        $orders = collect(range(1, $count))->map(
            fn(int $i): Order => $orderCreateService->execute($skus[$i % count($skus)])
        );

        $this->line("создано заказов: {$orders->count()}");

        $responses = Process::pool(function (Pool $pool) use ($orders): void {
            foreach ($orders as $order) {
                $pool->command($this->curlCommand($order));
            }
        })->start()->wait();

        $statuses = [];

        foreach ($responses as $response) {
            $code = trim($response->output());
            $statuses[$code] = ($statuses[$code] ?? 0) + 1;
        }

        $this->line('HTTP-ответы вебхуков: ' . json_encode($statuses));

        if (($statuses['200'] ?? 0) !== $orders->count()) {
            $this->error('не все вебхуки доставлены — проверьте --url, приложение должно отвечать на ' . $this->option('url'));

            return self::FAILURE;
        }

        $ids = $orders->map(fn(Order $order): string => $order->getKey())->all();

        for ($round = 1; $round <= (int) $this->option('drain-rounds'); $round++) {
            Artisan::call('queue:work', ['--stop-when-empty' => true]);

            $pending = Order::query()
                            ->whereIn('id', $ids)
                            ->whereIn('status', [OrderStatusEnum::PAID->value, OrderStatusEnum::DELIVERING->value])
                            ->count();

            if ($pending === 0) {
                $this->line("очередь добита за раундов: {$round}");
                break;
            }

            sleep(2);
        }

        return $this->report($ids);
    }

    private function report(array $ids): int
    {
        $this->newLine();
        $this->line('<comment>статусы заказов</comment>');

        $statuses = Order::query()
                         ->whereIn('id', $ids)
                         ->select('status', DB::raw('count(*) as c'))
                         ->groupBy('status')
                         ->pluck('c', 'status');

        foreach ($statuses as $status => $c) {
            $this->line(sprintf('  %-18s %d', $status, $c));
        }

        $this->newLine();
        $this->line('<comment>попытки у поставщиков</comment>');

        $attempts = OrderProcessingLog::query()
                                      ->whereIn('order_id', $ids)
                                      ->whereNotNull('vendor')
                                      ->select('vendor', 'result', DB::raw('count(*) as c'))
                                      ->groupBy('vendor', 'result')
                                      ->orderBy('vendor')
                                      ->orderBy('result')
                                      ->get();

        foreach ($attempts as $row) {
            $this->line(sprintf('  %-10s %-14s %d', $row->vendor->value, $row->result->value, $row->c));
        }

        $keys = VendorKey::query()->whereIn('order_id', $ids)->get();
        $delivered = (int) ($statuses[OrderStatusEnum::DELIVERED->value] ?? 0);

        $this->newLine();
        $this->line('<comment>инварианты</comment>');

        $failures = 0;
        $failures += $this->assert('выдано ключей = доставлено заказов', $keys->count(), $delivered);
        $failures += $this->assert('заказов с двумя ключами', $keys->groupBy('order_id')->filter(fn($g) => $g->count() > 1)->count(), 0);
        $failures += $this->assert('повторно выданных ключей', $keys->count() - $keys->pluck('key')->unique()->count(), 0);

        $this->newLine();

        if ($failures > 0) {
            $this->error("нарушено инвариантов: {$failures}");

            return self::FAILURE;
        }

        $this->info('инварианты выдачи соблюдены');

        return self::SUCCESS;
    }

    private function assert(string $title, int $actual, int $expected): int
    {
        $ok = $actual === $expected;

        $this->line(sprintf(
            '  %s %-36s ожидалось %-6s получено %s',
            $ok ? '<info>OK  </info>' : '<error>FAIL</error>',
            $title,
            (string) $expected,
            (string) $actual
        ));

        return $ok ? 0 : 1;
    }

    private function curlCommand(Order $order): string
    {
        $payload = json_encode([
            'event_id'   => 'evt_' . Str::lower(Str::random(12)),
            'order_id'   => $order->getKey(),
            'status'     => 'paid',
            'amount'     => (float) $order->price,
            'currency'   => $order->currency->value,
            'created_at' => now()->toIso8601ZuluString(),
        ], JSON_UNESCAPED_SLASHES);

        $url = rtrim((string) $this->option('url'), '/') . '/api/payment_systems/ps_mir/callback';

        return sprintf(
            'curl -s -o /dev/null -w "%%{http_code}" -X POST %s -H "Accept: application/json" -H "Content-Type: application/json" -d %s',
            escapeshellarg($url),
            escapeshellarg($payload)
        );
    }
}
