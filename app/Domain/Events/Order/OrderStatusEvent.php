<?php
declare(strict_types=1);

namespace App\Domain\Events\Order;

use App\Domain\Entity\Order\OrderStatusEnum;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

abstract class OrderStatusEvent
{
    use Dispatchable;

    public function __construct(
        public readonly string           $orderId,
        public readonly ?OrderStatusEnum $previousStatus = null,
        public readonly ?string          $reason = null,
        public readonly CarbonImmutable  $triggeredAt = new CarbonImmutable(),
    ) {

    }

    abstract public static function status(): OrderStatusEnum;

}
