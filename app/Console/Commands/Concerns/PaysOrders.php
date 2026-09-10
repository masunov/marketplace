<?php
declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\PaymentSystem\PaymentStatusEnum;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Оплата заказов прогона своими руками, с заведомо успешным исходом.
 *
 * Прогон выключает автосписание и платит сам: иначе исход разыгрывает заглушка в воркере,
 * и в выборку попадают заказы, за которые никто не платил, — а инварианты про потери
 * и сходимость денег считают их наравне с остальными.
 *
 * Вебхуки уходят настоящим HTTP на тот же адрес, куда стучалась бы заглушка, поэтому
 * проверяется весь путь: маршрут, валидация запроса, журнал событий, отсечение повторов.
 * Залпом, а не по одному: всплеск оплат — часть проверяемой картины.
 */
trait PaysOrders
{
    /**
     * @param  Collection<int, string>  $orderIds
     */
    private function payOrders(Collection $orderIds): void
    {
        $url    = (string) config('marketplace.payment_systems.ps_mir.callback_url');
        $orders = Order::query()->whereIn('id', $orderIds->all())->get();

        $responses = Http::pool(
            static fn(Pool $pool): array => $orders->map(
                static fn(Order $order) => $pool->acceptJson()->timeout(10)->post($url, [
                    'event_id'   => (string) Str::uuid7(),
                    'order_id'   => $order->id,
                    'status'     => PaymentStatusEnum::PAID->value,
                    'amount'     => (string) $order->total_amount,
                    'currency'   => $order->currency->value,
                    'created_at' => now()->toIso8601ZuluString(),
                ])
            )->all()
        );

        $delivered = collect($responses)
            ->filter(static fn($response): bool => $response instanceof Response && $response->successful())
            ->count();

        $this->line("оплачено вебхуком: {$delivered} из {$orders->count()}");

        if ($delivered < $orders->count()) {
            $this->warn("  часть вебхуков не доставлена — проверьте адрес {$url}");
        }
    }
}
