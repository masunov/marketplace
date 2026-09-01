<?php

namespace App\Http\Controllers\Api\Orders;

use App\Domain\Entity\Order\Order;
use App\Domain\Services\Orders\Create\Exceptions\ProductNotFoundException;
use App\Domain\Services\Orders\Create\OrderCreateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Order\OrderCreateRequest;
use App\Http\Resources\Api\Order\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class OrdersController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(OrderCreateRequest $request, OrderCreateService $orderCreateService): JsonResponse
    {
        try {
            $order = $orderCreateService->execute($request->validated()['sku']);
        } catch (ProductNotFoundException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'server error'], 500);
        }

        return OrderResource::make($order->load('product'))
                            ->response()
                            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $order = Str::isUuid($id)
            ? Order::findById($id)
            : null;

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $order->load(['product', 'relatedContent']);

        return OrderResource::make($order)->response();
    }
}
