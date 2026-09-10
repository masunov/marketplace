<?php
declare(strict_types=1);

namespace App\Domain\Services\Orders\Create;

use App\Domain\Entity\Order\Order;
use App\Domain\Entity\Order\OrderItem;
use App\Domain\Entity\Order\OrderItemStatusEnum;
use App\Domain\Entity\Order\OrderStatusEnum;
use App\Domain\Entity\Product\Product;
use App\Domain\Events\Order\OrderCreated;
use App\Domain\Services\Orders\Create\Exceptions\ProductNotFoundException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderCreateService
{

    /**
     * @param array<int, string> $skus
     * @throws ProductNotFoundException|\Throwable
     */
    public function execute(array $skus): Order
    {
        $products = $this->resolveProducts($skus);

        return DB::transaction(function () use ($products): Order {
            $order = new Order();
            $order->total_amount = $products->sum(static fn(Product $product): float => (float) $product->price);
            $order->currency     = $products->first()->currency;
            $order->status       = OrderStatusEnum::CREATED;
            $order->save();

            $items = $products->map(fn(Product $product): OrderItem => $this->addItem($order, $product));

            $order->setRelation('items', new EloquentCollection($items->all()));

            $order->setRelation('transactions', new EloquentCollection());

            OrderCreated::dispatch($order->id);

            return $order;
        });
    }

    private function addItem(Order $order, Product $product): OrderItem
    {
        $item = new OrderItem();

        $item->order_id   = $order->id;
        $item->product_id = $product->id;
        $item->request_id = Str::uuid7()->toString();
        $item->price      = $product->price;
        $item->currency   = $product->currency;
        $item->status     = OrderItemStatusEnum::PENDING;

        $item->save();

        $item->setRelation('product', $product);

        return $item;
    }

    /**
     * @param array $skus
     * @return Collection
     * @throws ProductNotFoundException
     */
    private function resolveProducts(array $skus): Collection
    {
        if (empty($skus)) {
            throw new ProductNotFoundException('Order must contain at least one item.');
        }

        $products = Product::getBySkus($skus)->keyBy('sku');

        return collect($skus)->map(static function (string $sku) use ($products): Product {
            if (!$products->has($sku)) {
                throw new ProductNotFoundException("Product {$sku} not found.");
            }

            return $products->get($sku);
        });
    }

}
