<?php
declare(strict_types=1);

namespace App\Domain\Services\Orders\Create;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Entity\Product\Product;
use App\Domain\Events\Order\OrderCreated;
use App\Domain\Services\Orders\Create\Exceptions\ProductNotFoundException;
use Illuminate\Support\Str;

class OrderCreateService
{

    public function execute(
        string $sku
    ): Order
    {
        $product = Product::findBySku($sku);

        if (!$product) {
            throw new ProductNotFoundException('Product not found.');
        }


        $order = new Order();
        $order->product_id = $product->id;
        $order->request_id = Str::uuid()->toString();
        $order->price = $product->price;
        $order->currency = $product->currency;
        $order->status = OrderStatusEnum::CREATED;
        $order->save();

        OrderCreated::dispatch($order->getKey());

        return $order;
    }

}
