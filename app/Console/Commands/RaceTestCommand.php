<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderProcessingLog;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Entity\PaymentSystem\PaymentCallbackLog;
use App\Domain\Entity\ProductVendor\VendorKey;
use App\Domain\Services\Orders\Create\OrderCreateService;
use Illuminate\Console\Command;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class RaceTestCommand extends Command
{
    protected $signature = 'marketplace:race-test
        {--requests=50 : сколько параллельных вебхуков отправить}
        {--sku=KEY-GTA5 : SKU для тестового заказа}
        {--url=http://127.0.0.1:8000 : базовый адрес приложения}
        {--same-event : слать один и тот же event_id вместо разных}
        {--chaos : не подавлять случайные отказы поставщиков}';

    protected $description = 'Отправляет N параллельных вебхуков оплаты по одному заказу и проверяет exactly-once';

    private int $failures = 0;

    public function handle(OrderCreateService $orderCreateService): int
    {
        if (!$this->option('chaos')) {
            config([
                'marketplace.vendors.vendor_a.outcomes.error'   => 0,
                'marketplace.vendors.vendor_a.outcomes.timeout' => 0,
            ]);
        }

        $requests = (int) $this->option('requests');
        $sameEvent = (bool) $this->option('same-event');

        $order = $orderCreateService->execute($this->option('sku'));

        $this->line("заказ:    {$order->getKey()}");
        $this->line("статус:   {$order->status->value}");
        $this->line("режим:    " . ($sameEvent ? 'один event_id на все запросы' : 'уникальный event_id у каждого запроса'));
        $this->newLine();

        $sharedEventId = 'evt_' . Str::lower(Str::random(12));

        $responses = Process::pool(function (Pool $pool) use ($requests, $order, $sameEvent, $sharedEventId): void {
            for ($i = 1; $i <= $requests; $i++) {
                $eventId = $sameEvent ? $sharedEventId : 'evt_' . Str::lower(Str::random(12));

                $pool->command($this->curlCommand($order, $eventId));
            }
        })->start()->wait();

        $statuses = [];

        foreach ($responses as $response) {
            $code = trim($response->output());
            $statuses[$code] = ($statuses[$code] ?? 0) + 1;
        }

        $this->line('HTTP-ответы: ' . json_encode($statuses));

        if (($statuses['200'] ?? 0) !== $requests) {
            $this->error('не все вебхуки доставлены — проверьте --url, приложение должно отвечать на ' . $this->option('url'));

            return self::FAILURE;
        }

        Artisan::call('queue:work', ['--stop-when-empty' => true]);

        $this->waitForTerminalStatus($order);

        $order->refresh();

        $this->newLine();
        $this->assert('все вебхуки приняты (200)', $statuses['200'] ?? 0, $requests);
        $this->assert(
            'событий в журнале платежей',
            PaymentCallbackLog::query()->where('order_id', $order->getKey())->count(),
            $sameEvent ? 1 : $requests
        );
        $this->assert('переходов created -> paid', $this->transitions($order, OrderStatusEnum::CREATED, OrderStatusEnum::PAID), 1);
        $this->assert('переходов paid -> delivering', $this->transitions($order, OrderStatusEnum::PAID, OrderStatusEnum::DELIVERING), 1);
        $keys = VendorKey::query()->where('order_id', $order->getKey())->count();

        $this->assert('выданных ключей по заказу', $keys, $order->status === OrderStatusEnum::DELIVERED ? 1 : 0);
        $this->assert('заказ в терминальном статусе', $order->status->isTerminalForDelivery(), true);

        if ($order->status !== OrderStatusEnum::DELIVERED) {
            $this->warn("  выдача завершилась статусом {$order->status->value} — это отказ поставщика, а не нарушение exactly-once");
        }

        $this->newLine();

        if ($this->failures > 0) {
            $this->error("ПРОВАЛЕНО проверок: {$this->failures}");

            return self::FAILURE;
        }

        $this->info('exactly-once подтвержден');

        return self::SUCCESS;
    }

    private function curlCommand(Order $order, string $eventId): string
    {
        $payload = json_encode([
            'event_id'   => $eventId,
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

    private function waitForTerminalStatus(Order $order, int $seconds = 120): void
    {
        $deadline = time() + $seconds;

        while (time() < $deadline) {
            $order->refresh();

            if ($order->status->isTerminalForDelivery()) {
                return;
            }

            sleep(2);
        }
    }

    private function transitions(Order $order, OrderStatusEnum $from, OrderStatusEnum $to): int
    {
        return OrderProcessingLog::query()
                                 ->where('order_id', $order->getKey())
                                 ->where('previous_status', $from->value)
                                 ->where('order_status', $to->value)
                                 ->count();
    }

    private function attempts(Order $order): int
    {
        return OrderProcessingLog::query()
                                 ->where('order_id', $order->getKey())
                                 ->whereNotNull('vendor')
                                 ->count();
    }

    private function assert(string $title, mixed $actual, mixed $expected): void
    {
        $ok = $actual === $expected;

        if (!$ok) {
            $this->failures++;
        }

        $this->line(sprintf(
            '  %s %-32s ожидалось %-12s получено %s',
            $ok ? '<info>OK  </info>' : '<error>FAIL</error>',
            $title,
            (string) $expected,
            (string) $actual
        ));
    }
}
